<?php

namespace Notmyhostname\Posse\Models;

use Kirby\Data\Yaml;

class Config
{
    private $configFile;
    private $config;
    private $defaults = [
        'contenttypes' => [],
        'syndication_delay' => 60,
        'auth' => [
            'token' => '',
            'enabled' => false
        ],
        'services' => [
            'mastodon' => [
                'enabled' => false,
                'instance_url' => '',
                'api_token' => '',
                'image_limit' => 4
            ],
            'bluesky' => [
                'enabled' => false,
                'instance_url' => '',
                'api_token' => '',
                'image_limit' => 4
            ]
        ],
        'use_original_image_size' => false,
        'image_preset' => '1800w'
    ];
    
    public function __construct()
    {
        $this->configFile = kirby()->root('config') . '/posse.yml';
        $this->load();
    }
    
    /**
     * Load configuration from file
     * 
     * @return array The loaded configuration
     */
    public function load()
    {
        try {
            // Read the YAML file
            if (file_exists($this->configFile)) {
                $yamlConfig = Yaml::read($this->configFile);
                
                // Merge with defaults to ensure all required fields exist
                $this->config = $this->mergeConfig($this->defaults, $yamlConfig ?: []);
            } else {
                // If file doesn't exist, use defaults and create the file
                $this->config = $this->defaults;
                $this->save($this->defaults);
            }
        } catch (\Exception $e) {
            // Log the error
            error_log('POSSE Plugin: Error loading config: ' . $e->getMessage());
            
            // If loading fails, use defaults
            $this->config = $this->defaults;
        }
        
        return $this->config;
    }
    
    /**
     * Save configuration to file
     * 
     * @param array $settings Settings to save
     * @return bool Success status
     */
    public function save($settings = null)
    {
        try {
            // If settings provided, update the internal config
            if ($settings) {
                $this->config = $this->mergeConfig($this->config, $settings);
            }
            
            // Ensure the config directory exists
            $configDir = dirname($this->configFile);
            if (!is_dir($configDir)) {
                if (!mkdir($configDir, 0755, true)) {
                    throw new \Exception("Could not create directory: $configDir");
                }
            }
            
            // Write the YAML file
            Yaml::write($this->configFile, $this->config);
            
            // Check if basic auth is enabled
            $this->checkBasicAuth();
            
            return true;
        } catch (\Exception $e) {
            // Log error
            error_log('POSSE Plugin: Error saving config: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the entire configuration
     * 
     * @return array Configuration data
     */
    public function get()
    {
        // Get the base configuration
        $config = $this->config;
        
        // Add thumb presets from Kirby
        $config['thumbs_presets'] = $this->getThumbPresets();
        
        return $config;
    }
    
    /**
     * Get available thumb presets from Kirby
     * 
     * @return array Array of thumb presets
     */
    public function getThumbPresets()
    {
        $presets = [];
        
        try {
            // Get presets from Kirby config
            $kirbyThumbsConfig = kirby()->option('thumbs', []);
            $srcsets = $kirbyThumbsConfig['srcsets'] ?? [];
            $presets = $kirbyThumbsConfig['presets'] ?? [];
            
            // Start with direct presets if any
            $result = [];
            
            // Format the standard presets
            foreach ($presets as $name => $options) {
                $result[] = [
                    'value' => $name,
                    'text' => $this->formatPresetName($name, $options)
                ];
            }
            
            // Add width-based presets from srcsets
            foreach ($srcsets as $setName => $set) {
                foreach ($set as $name => $options) {
                    // Create a width-based value like "300w"
                    $widthValue = $name;
                    
                    // For square presets, use a custom format
                    if (isset($options['width']) && isset($options['height']) && $options['width'] === $options['height'] && isset($options['crop']) && $options['crop'] === 'center') {
                        $widthValue = 'square-' . $name;
                    }
                    
                    $result[] = [
                        'value' => $widthValue,
                        'text' => $this->formatPresetName($name, $options)
                    ];
                }
            }
            
            // If no presets found, add some defaults
            if (empty($result)) {
                // Add default width presets
                $defaultWidths = ['300w', '600w', '900w', '1200w', '1800w'];
                foreach ($defaultWidths as $width) {
                    $widthNum = (int)str_replace('w', '', $width);
                    $result[] = [
                        'value' => $width,
                        'text' => "Width: {$widthNum}px"
                    ];
                }
                
                // Add default square presets
                foreach ($defaultWidths as $width) {
                    $widthNum = (int)str_replace('w', '', $width);
                    $squareValue = 'square-' . $width;
                    $result[] = [
                        'value' => $squareValue,
                        'text' => "Square: {$widthNum}×{$widthNum}px"
                    ];
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error getting thumb presets: ' . $e->getMessage());
            
            // Return default presets on error
            return [
                ['value' => '300w', 'text' => 'Width: 300px'],
                ['value' => '600w', 'text' => 'Width: 600px'],
                ['value' => '900w', 'text' => 'Width: 900px'],
                ['value' => '1200w', 'text' => 'Width: 1200px'],
                ['value' => '1800w', 'text' => 'Width: 1800px'],
                ['value' => 'square-300w', 'text' => 'Square: 300×300px'],
                ['value' => 'square-600w', 'text' => 'Square: 600×600px'],
                ['value' => 'square-900w', 'text' => 'Square: 900×900px'],
                ['value' => 'square-1200w', 'text' => 'Square: 1200×1200px'],
                ['value' => 'square-1800w', 'text' => 'Square: 1800×1800px']
            ];
        }
    }
    
    /**
     * Format a preset name for display
     * 
     * @param string $name The preset name
     * @param array $options The preset options
     * @return string Formatted name
     */
    protected function formatPresetName($name, $options)
    {
        // For width-only presets
        if (isset($options['width']) && !isset($options['height'])) {
            return "Width: {$options['width']}px";
        }
        
        // For square presets
        if (isset($options['width']) && isset($options['height']) && $options['width'] === $options['height'] && isset($options['crop']) && $options['crop'] === 'center') {
            return "Square: {$options['width']}×{$options['height']}px";
        }
        
        // For other presets with both dimensions
        if (isset($options['width']) && isset($options['height'])) {
            return "{$options['width']}×{$options['height']}px";
        }
        
        // Default fallback
        return $name;
    }
    
    /**
     * Get a specific configuration value using dot notation
     * 
     * @param string $key Configuration key (e.g., 'services.mastodon.enabled')
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function option($key, $default = null)
    {
        $keys = explode('.', $key);
        $config = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                return $default;
            }
            $config = $config[$k];
        }
        
        return $config;
    }
    
    /**
     * Get list of enabled services
     * 
     * @return array List of enabled service names
     */
    public function getEnabledServices()
    {
        $services = [];
        
        foreach ($this->config['services'] as $name => $service) {
            if (isset($service['enabled']) && $service['enabled']) {
                $services[] = $name;
            }
        }
        
        return $services;
    }
    
    /**
     * Get list of tracked content types
     * 
     * @return array List of tracked content type names
     */
    public function getTrackedContentTypes()
    {
        $types = [];
        
        foreach ($this->config['contenttypes'] as $type => $enabled) {
            if ($enabled) {
                $types[] = $type;
            }
        }
        
        return $types;
    }
    
    /**
     * Get configuration for a specific service
     * 
     * @param string $service Service name
     * @return array Service configuration
     */
    public function getServiceConfig($service)
    {
        return $this->option('services.' . $service, []);
    }
    
    /**
     * Recursively merge configurations
     * 
     * @param array $base Base configuration
     * @param array $override Override configuration
     * @return array Merged configuration
     */
    protected function mergeConfig($base, $override)
    {
        $result = $base;
        
        foreach ($override as $key => $value) {
            // If the key exists in base and both are arrays, merge them
            if (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                $result[$key] = $this->mergeConfig($result[$key], $value);
            } else {
                // Otherwise, override the base value
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Check if basic auth is enabled
     */
    protected function checkBasicAuth()
    {
        try {
            // Just validate that the basic auth option is set
            option('api.basicAuth');
        } catch (\Exception $e) {
            error_log('POSSE Plugin: Error checking basic auth: ' . $e->getMessage());
        }
    }
}