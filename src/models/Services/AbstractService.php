<?php

namespace Notmyhostname\Posse\Models\Services;

use Kirby\Cms\Page;
use Notmyhostname\Posse\Models\Config;
use Notmyhostname\Posse\Models\Database;

/**
 * Abstract base class for syndication services
 */
abstract class AbstractService implements ServiceInterface
{
    protected $db;
    protected $config;
    protected $serviceConfig;
    protected $serviceName;
    
    public function __construct(string $serviceName, Database $db, Config $config)
    {
        $this->serviceName = $serviceName;
        $this->db = $db;
        $this->config = $config;
        $this->serviceConfig = $config->option('services.' . $serviceName, []);
    }
    
    /**
     * Mark an item as syndicated
     */
    protected function markSyndicated($item, string $syndicatedUrl, Page $page): bool
    {
        // Convert ID to int to avoid type errors
        $id = (int)$item->id;
        
        // Store the syndicated URL in the page content
        $this->storeSyndicatedUrlInPage($page, $this->serviceName, $syndicatedUrl);
        
        // Mark the item as syndicated in the database
        return $this->db->markSyndicated($id, $syndicatedUrl);
    }
    
    /**
     * Store the syndicated URL in the page content
     */
    protected function storeSyndicatedUrlInPage(Page $page, string $service, string $url): bool
    {
        try {
            // Get existing URLs or create a new empty array
            $existingUrls = [];
            
            if ($page->syndicated_urls()->isNotEmpty()) {
                // This is a structure field, so we need to extract URLs differently
                $structure = $page->syndicated_urls()->toStructure();
                
                if ($structure->count() > 0) {
                    foreach ($structure as $item) {
                        if ($item->url()->isNotEmpty()) {
                            $existingUrls[] = $item->url()->value();
                        }
                    }
                }
            }
            
            // Check if our new URL already exists in the list
            if (!in_array($url, $existingUrls)) {
                // URL is not in the list, so add it
                $existingUrls[] = $url;
                
                try {
                    // Create a properly structured array for the syndicated_urls field
                    $structuredUrls = [];
                    foreach ($existingUrls as $urlItem) {
                        $structuredUrls[] = ['url' => $urlItem];
                    }
                    
                    // Convert the structured array to YAML format
                    $structuredYaml = \Kirby\Data\Yaml::encode($structuredUrls);
                    
                    // Update the page with the new URL added to the list, using structured format
                    try {
                        $page->update([
                            'syndicated_urls' => $structuredYaml  // Use underscore version to match blueprint
                        ]);
                        return true; // Successfully updated
                    } catch (\Exception $e) {
                        error_log('POSSE Plugin: Failed to update page with syndicated URL: ' . $e->getMessage());
                        return false;
                    }
                } catch (\Exception $e) {
                    error_log('POSSE Plugin: Failed to update page with syndicated URL: ' . $e->getMessage());
                    return false;
                }
            } else {
                // URL already exists, no changes needed
                return true;
            }
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error storing syndicated URL in page: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Prepare images for syndication
     * 
     * @param \Kirby\Cms\Page $page The page to extract images from
     * @param int $imageLimit Maximum number of images to process
     * @return array Array of processed Kirby file objects
     */
    protected function prepareImagesForSyndication(Page $page, int $imageLimit = 4): array
    {
        $images = [];
        
        // First check if the page has a cover image
        if ($page->cover()->isNotEmpty()) {
            $coverFile = $page->cover()->toFile();
            if ($coverFile) {
                $images[] = $coverFile;
            }
        }
        
        // If no cover image or we need more images, check for general images
        if (count($images) == 0 && $page->hasImages()) {
            $pageImages = $page->files()->filterBy('type', 'image')->limit($imageLimit);
            if ($pageImages->count() > 0) {
                // Add each image to the array while preserving the Kirby File objects
                foreach ($pageImages as $image) {
                    $images[] = $image;
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Check if service is configured properly
     */
    protected function isConfigured(): bool
    {
        return !empty($this->serviceConfig) && 
               isset($this->serviceConfig['enabled']) && 
               $this->serviceConfig['enabled'] === true;
    }
    
    /**
     * Get configuration option with default
     */
    protected function getOption(string $key, $default = null)
    {
        if (isset($this->serviceConfig[$key])) {
            return $this->serviceConfig[$key];
        }
        
        return $default;
    }
    
    /**
     * Gets the preset name to use with Kirby's thumb system
     * 
     * @param string $preset The preset name
     * @return string The preset name to use with Kirby
     */
    protected function getThumbPreset(string $preset): string
    {
        // Handle special case for square presets
        if (strpos($preset, 'square-') === 0) {
            // For square presets, we just use the original width value
            // The proper preset will be applied by Kirby
            return str_replace('square-', '', $preset);
        }
        
        // Return the preset name as-is for direct use with Kirby's thumb system
        return $preset;
    }
}