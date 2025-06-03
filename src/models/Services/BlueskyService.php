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
        // Check required configuration
        if (empty($this->serviceConfig['api_token'])) {
            return [
                'status' => 'error',
                'message' => 'Bluesky credentials are not configured. Please update the <a href="/panel/site?tab=posse">POSSE settings</a>.'
            ];
        }
        
        try {
            // Parse the API token string which should be in format "handle:password"
            
            // Trim whitespace from token
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
            
            // Create a new BlueskyApi instance and authenticate
            try {
                // Get Bluesky host from the instance URL
                $instanceUrl = $this->getOption('instance_url', 'https://bsky.social');
                $host = str_replace(['https://', 'http://'], '', $instanceUrl);
                $host = rtrim($host, '/');
                
                // Create a new BlueskyApi instance with the host
                $bluesky = new BlueskyApi($host);
                
                // Authenticate with the API
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
            
            // Process content and extract facets
            $content = $this->sanitizeContent($content);
            
            // Extract URL facets
            $urlFacets = $this->extractUrlFacets($content);
            
            // Extract hashtag facets
            $hashtagFacets = $this->extractHashtagFacets($content);
            
            // Combine all facets
            $facets = array_merge($urlFacets, $hashtagFacets);
            
            // Create base record
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $content,
                'createdAt' => date('c')
            ];
            
            // Add facets if any exist
            if (!empty($facets)) {
                $record['facets'] = $facets;
            }
            
            // Process images
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
            
            // Get the account DID
            $did = $bluesky->getAccountDid();
            if (!$did) {
                throw new \Exception('Failed to get account DID');
            }
            
            // Create the post structure
            $post = [
                'collection' => 'app.bsky.feed.post',
                'repo' => $did,
                'record' => $record
            ];
            
            // Create the post
            $response = $bluesky->request('POST', 'com.atproto.repo.createRecord', $post);
            
            if (!$response) {
                throw new \Exception('Failed to create post - empty response');
            }
            
            // Extract URI from response
            if (isset($response->uri)) {
                // Format is AT URI: at://did:plc:xxxxx/app.bsky.feed.post/yyyy
                $atUri = $response->uri;
                $recordId = basename($atUri);
                
                // The syndicated URL that links directly to the post
                $syndicatedUrl = "https://bsky.app/profile/{$did}/post/{$recordId}";
                
                // Mark as syndicated
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
     */
    protected function extractUrlFacets(string $content): array
    {
        // Convert literal \n to actual newlines
        $content = str_replace('\n', "\n", $content);
        
        // Split content into lines for processing
        $lines = explode("\n", $content);
        
        $facets = [];
        $currentBytePosition = 0;
        
        // Process each line
        foreach ($lines as $line) {
            // Use a simpler URL regex that handles localhost
            $urlPattern = '/(?:^|\s|\()((https?:\/\/[-a-zA-Z0-9@:%._\+~#=]{1,256}(?:\.[a-zA-Z0-9()]{1,6})?\b(?:[-a-zA-Z0-9()@:%_\+.~#?&\/=]*[-a-zA-Z0-9@%_\+~#\/=])))/u';
            
            if (preg_match_all($urlPattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $match) {
                    $url = $match[0];
                    $position = $match[1];
                    
                    // Calculate byte positions relative to the entire content
                    $byteStart = $currentBytePosition + $position;
                    $byteEnd = $byteStart + strlen($url);
                    
                    // Validate URL
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        // Create URL facet
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
            
            // Update byte position for next line
            $currentBytePosition += strlen($line) + 1; // +1 for the newline
        }
        
        return $facets;
    }
    
    /**
     * Extract hashtags from content and create facets for Bluesky
     * 
     * @param string $content The content to extract hashtags from
     * @return array Array of facets for Bluesky
     */
    protected function extractHashtagFacets(string $content): array
    {
        $facets = [];
        
        // Regular expression to match hashtags
        // Match #word pattern, allowing alphanumeric characters and underscores
        // The hashtag can't be part of a larger word, so we check for word boundaries
        if (preg_match_all('/(?<=\s|^|\n)(#([a-zA-Z0-9_]+))/u', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $match) {
                $fullTag = $match[0]; // The full hashtag including #
                $position = $match[1]; // The byte position in the content
                $tagName = $matches[2][$index][0]; // Just the tag name without #
                
                // Get the byte length of the full hashtag
                $byteLength = strlen($fullTag);
                
                // Add the facet with proper byte positions
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
                // Get image file with proper size
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
                
                // Validate image file exists and is readable
                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                    error_log('POSSE Plugin: Image file not accessible: ' . $imagePath);
                    continue;
                }
                
                // Get file info
                $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $fileInfo->file($imagePath);
                
                // Validate mime type
                if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                    error_log('POSSE Plugin: Unsupported image type: ' . $mimeType);
                    continue;
                }
                
                // Get alt text
                $altText = ($image->alt()->exists() && $image->alt()->isNotEmpty())
                    ? $image->alt()->value()
                    : $page->title()->value();
                
                // Read image data
                $imageData = file_get_contents($imagePath);
                if ($imageData === false) {
                    error_log('POSSE Plugin: Failed to read image file: ' . $imagePath);
                    continue;
                }
                
                // Upload image
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