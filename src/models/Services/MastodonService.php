<?php

namespace Notmyhostname\Posse\Models\Services;

use Kirby\Cms\Page;
use Notmyhostname\Posse\Models\Config;
use Notmyhostname\Posse\Models\Database;

/**
 * Mastodon syndication service implementation
 */
class MastodonService extends AbstractService
{
    public function __construct(Database $db, Config $config)
    {
        parent::__construct('mastodon', $db, $config);
    }
    
    /**
     * Syndicate to Mastodon
     * 
     * @param object $item The queue item
     * @param \Kirby\Cms\Page $page The page to syndicate
     * @param string $content The processed content
     * @return array Response with status and syndicated URL
     */
    public function syndicate($item, Page $page, string $content): array
    {
        // Check required configuration
        if (empty($this->serviceConfig['instance_url'])) {
            return [
                'status' => 'error',
                'message' => 'Mastodon instance URL is not configured. Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
            ];
        }
        
        if (empty($this->serviceConfig['api_token'])) {
            return [
                'status' => 'error',
                'message' => 'Mastodon API token is not configured. Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
            ];
        }
        
        try {
            // Instance URL without trailing slash
            $instanceUrl = rtrim($this->serviceConfig['instance_url'], '/');
            $apiToken = $this->serviceConfig['api_token'];
            
            // Collect media files to upload
            $mediaIds = [];
            
            // Get image limit from service config
            $imageLimit = $this->serviceConfig['image_limit'] ?? 4;
            
            // Get images from the page
            $images = $this->prepareImagesForSyndication($page, $imageLimit);
            
            // Process and upload images
            foreach ($images as $image) {
                try {
                    // Use config for image size
                    $useOriginal = $this->config->option('use_original_image_size', false);
                    $preset = $this->config->option('image_preset', '1800w');
                    if ($useOriginal) {
                        $imageFile = $image; // Use original
                    } else {
                        // Use the getThumbPreset method to get the preset name
                        $presetName = $this->getThumbPreset($preset);
                        $imageFile = $image->thumb($presetName);
                        $imageFile->save();
                    }
                    $imagePath = $imageFile->root();
                    
                    // Get alt text
                    $altText = ($image->alt()->exists() && $image->alt()->isNotEmpty()) 
                        ? $image->alt()->value() 
                        : $page->title()->value();
                    
                    // Upload to Mastodon
                    $mediaId = $this->uploadMediaToMastodon($instanceUrl, $apiToken, $imagePath, $altText);
                    if ($mediaId) {
                        $mediaIds[] = $mediaId;
                    } else {
                        error_log('POSSE Plugin: Failed to get media ID for image: ' . $imagePath);
                    }
                } catch (\Exception $e) {
                    error_log('POSSE Plugin: Error processing image: ' . $e->getMessage());
                }
            }
            
            $result = $this->postToMastodon($instanceUrl, $apiToken, $content, $mediaIds);
            
            if (isset($result['id'])) {
                // Construct the URL to the status
                $username = $result['account']['username'];
                $statusId = $result['id'];
                $syndicatedUrl = $instanceUrl . '/@' . $username . '/' . $statusId;
                
                // Mark as syndicated in the database
                $this->markSyndicated($item, $syndicatedUrl, $page);
                
                return [
                    'status' => 'success',
                    'message' => 'Successfully syndicated to Mastodon',
                    'syndicated_url' => $syndicatedUrl
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Failed to post to Mastodon: API response did not contain status ID'
                ];
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error syndicating to Mastodon: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Error syndicating to Mastodon: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload media to Mastodon
     * 
     * @param string $instanceUrl The Mastodon instance URL
     * @param string $apiToken The Mastodon API token
     * @param string $filePath The path to the file to upload
     * @param string $description The alt text for the image
     * @return string|null The media ID if successful, null otherwise
     */
    protected function uploadMediaToMastodon($instanceUrl, $apiToken, $filePath, $description = ''): ?string
    {
        // Check if file exists
        if (!file_exists($filePath)) {
            error_log('POSSE Plugin: File not found: ' . $filePath);
            return null;
        }
        
        // Get file info
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($filePath);
        
        
        // Create a curl handle for the upload
        $ch = curl_init();
        
        $url = $instanceUrl . '/api/v1/media';
        
        // Create the CURL file
        try {
            $cFile = new \CURLFile($filePath, $mimeType, basename($filePath));
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error creating CURLFile: ' . $e->getMessage());
            return null;
        }
        
        $postFields = [
            'file' => $cFile
        ];
        
        // Add description only if not empty
        if (!empty($description)) {
            $postFields['description'] = $description;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log('POSSE Plugin: cURL error uploading media: ' . $error);
            return null;
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('POSSE Plugin: JSON parse error in media upload: ' . json_last_error_msg() . ', Response: ' . $response);
                return null;
            }
            
            if (isset($data['id'])) {
                return $data['id'];
            }
        }
        
        error_log('POSSE Plugin: Failed to upload media to Mastodon. HTTP Code: ' . $httpCode . ', Response: ' . $response);
        return null;
    }
    
    /**
     * Post a status to Mastodon
     * 
     * @param string $instanceUrl The Mastodon instance URL
     * @param string $apiToken The Mastodon API token
     * @param string $content The status content
     * @param array $mediaIds The IDs of uploaded media to attach
     * @return array The API response
     */
    protected function postToMastodon($instanceUrl, $apiToken, $content, $mediaIds = []): array
    {
        // Convert literal \n to actual newlines
        $content = str_replace('\n', "\n", $content);
        
        $url = $instanceUrl . '/api/v1/statuses';
        
        $postData = [
            'status' => $content,
            'visibility' => 'public'
        ];
        
        // Ensure $mediaIds is an array and not null before checking if it's empty
        if (!is_array($mediaIds)) {
            $mediaIds = [];
        }
        
        $ch = curl_init();
        
        if (!empty($mediaIds)) {
            // For Mastodon, we need to structure the media_ids as an array
            // Using form-urlencoded format with media_ids[]=id1&media_ids[]=id2
            $formData = 'status=' . urlencode($content) . '&visibility=public';
            
            foreach ($mediaIds as $id) {
                $formData .= '&media_ids[]=' . urlencode($id);
            }
            
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $formData,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
        } else {
            // No media - use the regular JSON format
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($postData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiToken,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log('POSSE Plugin: cURL error: ' . $error);
            throw new \Exception('cURL error: ' . $error);
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('POSSE Plugin: JSON parse error: ' . json_last_error_msg() . ', Response: ' . $response);
                throw new \Exception('JSON parse error: ' . json_last_error_msg());
            }
            return $data ?: [];
        }
        
        error_log('POSSE Plugin: Failed to post to Mastodon. HTTP Code: ' . $httpCode . ', Response: ' . $response);
        throw new \Exception('Failed to post to Mastodon (HTTP ' . $httpCode . '): ' . $response);
    }
}