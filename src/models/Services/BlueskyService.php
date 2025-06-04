<?php

namespace Notmyhostname\Posse\Models\Services;

use Kirby\Cms\Page;
use Notmyhostname\Posse\Models\Config;
use Notmyhostname\Posse\Models\Database;
use cjrasmussen\BlueskyApi\BlueskyApi;

/**
 * Bluesky syndication service implementation
 */
class BlueskyService extends AbstractService
{
    /**
     * Constructor for BlueskyService
     * 
     * @param Database $db Database instance
     * @param Config $config Configuration instance
     */
    public function __construct(Database $db, Config $config)
    {
        parent::__construct('bluesky', $db, $config);
    }
    
    /**
     * Syndicate to Bluesky
     * 
     * @param object $item The queue item
     * @param \Kirby\Cms\Page $page The page to syndicate
     * @param string $content The processed content
     * @return array Response with status and syndicated URL
     */
    public function syndicate($item, Page $page, string $content): array
    {
        if (empty($this->serviceConfig['api_token'])) {
            return [
                'status' => 'error',
                'message' => 'Bluesky credentials are not configured. Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
            ];
        }
        
        try {
            $apiToken = trim($this->serviceConfig['api_token']);
            $apiTokenParts = explode(':', $apiToken);
            
            if (count($apiTokenParts) !== 2) {
                return [
                    'status' => 'error',
                    'message' => 'Bluesky API token must be in format "handle:password". Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
                ];
            }
            
            list($handle, $password) = $apiTokenParts;
            
            // Important: The handle should be the full Bluesky handle, e.g. username.bsky.social
            // Ensure it doesn't have any whitespace
            $handle = trim($handle);
            $password = trim($password);
            
            try {
                $instanceUrl = $this->getOption('instance_url', 'https://bsky.social');
                $host = str_replace(['https://', 'http://'], '', $instanceUrl);
                $host = rtrim($host, '/');
                
                $bluesky = new BlueskyApi($host);
                
                if (!$bluesky->auth($handle, $password)) {
                    throw new \Exception('Authentication failed');
                }
                
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                error_log('POSSE Plugin: Error authenticating with Bluesky: ' . $errorMessage);
                return [
                    'status' => 'error',
                    'message' => 'Failed to authenticate with Bluesky: ' . $errorMessage
                ];
            }
            
            $content = $this->sanitizeContent($content);
            $urlFacets = $this->extractUrlFacets($content);
            $hashtagFacets = $this->extractHashtagFacets($content);
            $facets = array_merge($urlFacets, $hashtagFacets);
            
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $content,
                'createdAt' => date('c')
            ];
            
            if (!empty($facets)) {
                $record['facets'] = $facets;
            }
            
            $imageLimit = $this->getOption('image_limit', 4);
            $images = $this->prepareImagesForSyndication($page, $imageLimit);
            
            if (!empty($images)) {
                $processedImages = $this->processImages($images, $bluesky, $page);
                
                if (!empty($processedImages)) {
                    $record['embed'] = [
                        '$type' => 'app.bsky.embed.images',
                        'images' => $processedImages
                    ];
                }
            }
            
            $did = $bluesky->getAccountDid();
            if (!$did) {
                throw new \Exception('Failed to get account DID');
            }
            
            $post = [
                'collection' => 'app.bsky.feed.post',
                'repo' => $did,
                'record' => $record
            ];
            
            $response = $bluesky->request('POST', 'com.atproto.repo.createRecord', $post);
            
            if (!$response) {
                throw new \Exception('Failed to create post - empty response');
            }
            
            if (isset($response->uri)) {
                // Format is AT URI: at://did:plc:xxxxx/app.bsky.feed.post/yyyy
                $atUri = $response->uri;
                $recordId = basename($atUri);
                $syndicatedUrl = "https://bsky.app/profile/{$did}/post/{$recordId}";
                
                $this->markSyndicated($item, $syndicatedUrl, $page);
                
                return [
                    'status' => 'success',
                    'message' => 'Successfully syndicated to Bluesky',
                    'syndicated_url' => $syndicatedUrl
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Failed to post to Bluesky: API response did not contain URI'
                ];
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error syndicating to Bluesky: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Error syndicating to Bluesky: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Extract URL facets from content
     * 
     * @param string $content The content to extract URLs from
     * @return array Array of URL facets for Bluesky
     */
    protected function extractUrlFacets(string $content): array
    {
        // Convert literal \n to actual newlines
        $content = str_replace('\n', "\n", $content);
        $lines = explode("\n", $content);
        
        $facets = [];
        $currentBytePosition = 0;
        
        foreach ($lines as $line) {
            $urlPattern = '/(?:^|\s|\()((https?:\/\/[-a-zA-Z0-9@:%._\+~#=]{1,256}(?:\.[a-zA-Z0-9()]{1,6})?\b(?:[-a-zA-Z0-9()@:%_\+.~#?&\/=]*[-a-zA-Z0-9@%_\+~#\/=])))/u';
            
            if (preg_match_all($urlPattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $url = $match[0];
                    $position = $match[1];
                    
                    $byteStart = $currentBytePosition + $position;
                    $byteEnd = $byteStart + strlen($url);
                    
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $facets[] = [
                            'index' => [
                                'byteStart' => $byteStart,
                                'byteEnd' => $byteEnd
                            ],
                            'features' => [
                                [
                                    '$type' => 'app.bsky.richtext.facet#link',
                                    'uri' => $url
                                ]
                            ]
                        ];
                    }
                }
            }
            
            $currentBytePosition += strlen($line) + 1;
        }
        
        return $facets;
    }
    
    /**
     * Extract hashtags from content and create facets for Bluesky
     * 
     * @param string $content The content to extract hashtags from
     * @return array Array of hashtag facets for Bluesky
     */
    protected function extractHashtagFacets(string $content): array
    {
        $facets = [];
        
        if (preg_match_all('/(?<=\s|^|\n)(#([a-zA-Z0-9_]+))/u', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $match) {
                $fullTag = $match[0];
                $position = $match[1];
                $tagName = $matches[2][$index][0];
                
                $byteLength = strlen($fullTag);
                
                $facets[] = [
                    'index' => [
                        'byteStart' => $position,
                        'byteEnd' => $position + $byteLength
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#tag',
                            'tag' => $tagName
                        ]
                    ]
                ];
            }
        }
        
        return $facets;
    }

    /**
     * Process and upload images for Bluesky
     * 
     * @param array $images Array of Kirby image objects
     * @param BlueskyApi $bluesky Bluesky API instance
     * @param Page $page The page object for alt text fallback
     * @return array Array of processed image records
     */
    protected function processImages(array $images, BlueskyApi $bluesky, Page $page): array
    {
        $processedImages = [];
        
        foreach ($images as $image) {
            try {
                $useOriginal = $this->config->option('use_original_image_size', false);
                $preset = $this->config->option('image_preset', '1800w');
                
                if ($useOriginal) {
                    $imageFile = $image;
                } else {
                    $presetName = $this->getThumbPreset($preset);
                    $imageFile = $image->thumb($presetName);
                    $imageFile->save();
                }
                
                $imagePath = $imageFile->root();
                
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    error_log('POSSE Plugin: Image file not accessible: ' . $imagePath);
                    continue;
                }
                
                $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($imagePath);
                
                if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                    error_log('POSSE Plugin: Unsupported image type: ' . $mimeType);
                    continue;
                }
                
                $altText = ($image->alt()->exists() && $image->alt()->isNotEmpty())
                    ? $image->alt()->value()
                    : $page->title()->value();
                
                $imageData = file_get_contents($imagePath);
                if ($imageData === false) {
                    error_log('POSSE Plugin: Failed to read image file: ' . $imagePath);
                    continue;
                }
                
                $response = $bluesky->request('POST', 'com.atproto.repo.uploadBlob', [], $imageData, $mimeType);
                
                if (isset($response->blob)) {
                    $processedImages[] = [
                        'alt' => $altText,
                        'image' => $response->blob
                    ];
                } else {
                    error_log('POSSE Plugin: Failed to upload image: ' . $imagePath);
                }
            } catch (\Exception $e) {
                error_log('POSSE Plugin: Error processing image: ' . $e->getMessage());
            }
        }
        
        return $processedImages;
    }

    /**
     * Sanitize content for Bluesky
     * 
     * @param string $content The content to sanitize
     * @return string Sanitized content
     */
    protected function sanitizeContent(string $content): string
    {
        // First, convert any literal \n strings to actual newlines
        $content = str_replace('\n', "\n", $content);
        
        // Remove control characters (except newlines)
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
        
        // Normalize line endings to \n
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        // Ensure URLs are properly separated with spaces
        $content = preg_replace('/([^\s])(https?:\/\/)/', '$1 $2', $content);
        
        // Remove excessive line breaks (more than 2 consecutive)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Ensure double line breaks between sections
        $content = preg_replace('/([^\n])\n([^\n])/', '$1\n\n$2', $content);
        
        // Trim whitespace while preserving internal line breaks
        return trim($content);
    }
}