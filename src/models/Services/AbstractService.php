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