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
            
            // Sanitize content - remove special characters that could cause issues
            $content = preg_replace('/[\x00-\x1F\x7F]/', '', $content);
            
            // Format content specifically for Bluesky with proper newlines
            $blueskyContent = $this->formatContentForBluesky($page, $content);
            
            // Create a new post with text and images
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $blueskyContent, // Content with properly formatted newlines
                'createdAt' => date('c')
            ];
            
            // Get image limit from service config
            $imageLimit = $this->getOption('image_limit', 4);
            
            // Get images from the page
            $images = $this->prepareImagesForSyndication($page, $imageLimit);
            
            // Process and upload images
            if (count($images) > 0) {
                
                $record['embed'] = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => []
                ];
                
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
                        
                        // Get file info for upload
                        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $fileInfo->file($imagePath);
                        
                        // Upload the image using the v2 API
                        $imageData = file_get_contents($imagePath);
                        $response = $bluesky->request('POST', 'com.atproto.repo.uploadBlob', [], $imageData, $mimeType);
                        
                        if (isset($response->blob)) {
                            // Add the image to the embed
                            $record['embed']['images'][] = [
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
                
                // If no images were uploaded successfully, remove the embed
                if (empty($record['embed']['images'])) {
                    unset($record['embed']);
                }
            }
            
            // Add facets for URLs and hashtags in the content
            $urlFacets = $this->extractUrlFacets($blueskyContent);
            $hashtagFacets = $this->extractHashtagFacets($blueskyContent);
            $record['facets'] = array_merge($urlFacets, $hashtagFacets);
            
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
     * Format content specifically for Bluesky with proper newlines
     * 
     * @param \Kirby\Cms\Page $page The page for context
     * @param string $content The original processed content
     * @return string Content formatted for Bluesky
     */
    protected function formatContentForBluesky(Page $page, string $content): string
    {
        // Get the template from config
        $template = $this->config->option('template', '{{title}} {{url}} {{tags}}');
        
        // First detect if content matches the pattern of title immediately followed by URL
        if (preg_match('/(.*?)(https?:\/\/[^\s]+)(.*)/', $content, $matches)) {
            // Extract title, URL, and remainder (tags)
            $title = trim($matches[1]);
            $url = $matches[2];
            $remainder = trim($matches[3]);
            
            // Now build content with explicit newlines for Bluesky
            $blueskyContent = $title . "\n\n" . $url;
            
            // Add remainder (tags) if any
            if (!empty($remainder)) {
                $blueskyContent .= "\n\n" . $remainder;
            }
        } else {
            // If we can't detect title/URL pattern, use literal substitution
            // Replace single space between {{title}} and {{url}} in template with double newline
            $blueskyTemplate = preg_replace('/{{title}}\s+{{url}}/', '{{title}}\n\n{{url}}', $template);
            
            // Replace single space between {{url}} and {{tags}} with double newline
            $blueskyTemplate = preg_replace('/{{url}}\s+{{tags}}/', '{{url}}\n\n{{tags}}', $blueskyTemplate);
            
            // Process the template with our custom Bluesky template
            $blueskyContent = $this->processTemplate($page, $blueskyTemplate);
            
        }
        
        return $blueskyContent;
    }
    
    /**
     * Process a template with page content
     * 
     * @param \Kirby\Cms\Page $page The page to use for template variables
     * @param string $template The template to process
     * @return string The processed content
     */
    protected function processTemplate(Page $page, string $template): string
    {
        
        // Basic replacements
        $replacements = [
            '{{title}}' => $page->title()->value(),
            '{{url}}' => $page->url(),
            '{{date}}' => $page->date()->exists() ? $page->date()->toDate('Y-m-d') : date('Y-m-d')
        ];
        
        // Add tags if available
        if ($page->tags()->exists() && $page->tags()->isNotEmpty()) {
            $tags = $page->tags()->split();
            $hashTags = array_map(function($tag) {
                // Clean the tag (remove spaces, special chars, but keep underscores)
                // Bluesky hashtags can contain alphanumeric characters and underscores
                $tag = preg_replace('/[^a-zA-Z0-9_]/', '', $tag);
                return '#' . $tag;
            }, $tags);
            
            $replacements['{{tags}}'] = implode(' ', $hashTags);
        } else {
            $replacements['{{tags}}'] = '';
        }
        
        // Replace all placeholders
        $content = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Clean up extra newlines from empty placeholders
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        $content = trim($content);
        
        
        return $content;
    }
    
    /**
     * Extract URLs and hashtags from content and create facets for Bluesky
     * 
     * @param string $content The content to extract URLs and hashtags from
     * @return array Array of facets for Bluesky
     */
    protected function extractUrlFacets(string $content): array
    {
        $facets = [];
        
        // Extract URLs from content
        $words = preg_split('/\s+/', $content);
        
        foreach ($words as $word) {
            // Trim any punctuation from the end of the word
            $word = rtrim($word, '.,:;!?()[]{}<>');
            
            // Check if the word is a URL using PHP's filter_var
            if (filter_var($word, FILTER_VALIDATE_URL)) {
                // Find the position of this URL in the original string
                $position = strpos($content, $word);
                
                if ($position !== false) {
                    $byteLength = strlen($word);
                    
                    // Add the facet with proper byte positions
                    $facets[] = [
                        'index' => [
                            'byteStart' => $position,
                            'byteEnd' => $position + $byteLength
                        ],
                        'features' => [
                            [
                                '$type' => 'app.bsky.richtext.facet#link',
                                'uri' => $word
                            ]
                        ]
                    ];
                }
            }
        }
        
        // Extract hashtags from content
        preg_match_all('/#([a-zA-Z0-9_]+)/u', $content, $matches, PREG_OFFSET_CAPTURE);
        
        if (!empty($matches[0])) {
            foreach ($matches[0] as $index => $match) {
                $fullHashtag = $match[0]; // The full hashtag with #
                $position = $match[1];    // Position in the content
                $tag = $matches[1][$index][0]; // Just the tag part without the #
                $byteLength = strlen($fullHashtag);
                
                // Add the facet for the hashtag
                $facets[] = [
                    'index' => [
                        'byteStart' => $position,
                        'byteEnd' => $position + $byteLength
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#tag',
                            'tag' => $tag
                        ]
                    ]
                ];
            }
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
}