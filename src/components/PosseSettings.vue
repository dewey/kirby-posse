<template>
  <k-inside>
    <k-view class="posse-view">
      <k-header>
        POSSE Settings
        <k-button-group slot="buttons">
          <k-button icon="check" @click="saveSettings">Save</k-button>
        </k-button-group>
      </k-header>

      <k-grid style="gap: 2.5rem">
        <k-column width="1/1">
          <div v-if="loading">
            <k-text>Loading settings...</k-text>
          </div>

          <div v-else class="posse-settings-content">
            <k-form
              :fields="formFields"
              v-model="formData"
              @submit="saveSettings"
            />
          </div>
        </k-column>
      </k-grid>
    </k-view>
  </k-inside>
</template>

<script>
export default {
  props: {
    csrf: {
      type: String,
      required: false,
      default: ''
    }
  },
  data() {
    return {
      loading: true,
      formData: {
        contenttypes: '',
        syndication_delay: 60,
        auth_enabled: false,
        auth_token: '',
        mastodon_enabled: false,
        mastodon_instance_url: '',
        mastodon_api_token: '',
        mastodon_image_limit: 4,
        bluesky_enabled: false,
        bluesky_instance_url: '',
        bluesky_api_token: '',
        bluesky_image_limit: 4,
        use_original_image_size: false,
        image_preset: '1800w',
      },
      formFields: {
        // General Settings
        general_settings: {
          label: 'General Settings',
          type: 'headline',
          width: '1/1'
        },
        contenttypes: {
          label: 'Content Types to Track',
          type: 'text',
          help: 'Comma-separated list of page templates to track (e.g., "post, photo"). Leave empty to configure later.',
          width: '1/3'
        },
        syndication_delay: {
          label: 'Delay (minutes)',
          type: 'number',
          min: 0,
          default: 60,
          help: 'Time to wait before syndication, giving you a chance to fix typos or errors',
          width: '1/3'
        },
        template: {
          label: 'Post Template',
          type: 'info',
          text: 'The default template that defines how a post is structured on a service is defined in the plugin\'s snippets. You can customize it by creating your own snippets in your site\'s snippets directory. Currently supported services are:<br><br><strong>Mastodon:</strong> <code>snippets/posse/mastodon.php</code><br><strong>Bluesky:</strong> <code>snippets/posse/bluesky.php</code><br><br>If no service-specific snippet is found, it will fall back to the plugin provided default template. See the <a href="https://getkirby.com/docs/guide/templates/snippets" target="_blank">Kirby documentation</a> for more information about snippets.',
          width: '2/3'
        },

        // Image Settings
        image_settings: {
          label: 'Image Settings',
          type: 'headline',
          width: '1/1',
          help: 'Control how images are processed before being uploaded to social media platforms.'
        },
        image_settings_info: {
          type: 'info',
          text: 'If the image size exceeds the service\'s limit (e.g., Bluesky\'s 976.56KB limit), the plugin will automatically try progressively smaller presets. If no presets are defined in Kirby\'s config, it will fall back to WebP format with 1000px width and 90% quality. <a href="https://getkirby.com/docs/guide/files/resize-images-on-the-fly#presets" target="_blank">Configure this list</a> in your Kirby config.',
          width: '2/3'
        },
        image_settings_spacer: {
          type: 'info',
          text: ' ',
          theme: 'none',
          width: '1/3'
        },
        use_original_image_size: {
          label: 'Use original image size',
          type: 'toggle',
          text: ['No', 'Yes'],
          width: '1/3',
          help: 'If enabled, images will be uploaded in their original size. If disabled, you can specify a preset (e.g., 900w, 1800w) to resize images before uploading.'
        },
        image_preset: {
          label: 'Image size preset',
          type: 'select',
          width: '1/3',
          help: 'Select a preset for image resizing. Width options maintain aspect ratio, while Square options crop to an exact square.',
          options: [],  // Will be populated dynamically from API response
          default: '1800w',
          when: { use_original_image_size: false }
        },

        // Automated Syndication
        scheduling_headline: {
          label: 'Automated Syndication',
          type: 'headline',
          help: 'Automated syndication shares your posts on social media platforms without manual intervention, using the configured delay time to process items from the queue when they\'re ready. Only one post will be syndicated per hour, even if the cron job runs every 10 minutes. This prevents flooding your social media accounts.',
          width: '2/3'
        },
        scheduling_auth: {
          label: 'Authentication',
          type: 'info',
          text: 'This feature requires authentication. You can use either <a href="https://getkirby.com/docs/guide/api/authentication#http-basic-auth" target="_blank">Basic Auth</a> or configure a token below. If a token is configured, it will take precedence over Basic Auth. Token authentication is required if you have <a href="https://getkirby.com/docs/guide/authentication#two-factor-authentication" target="_blank">Two-Factor Authentication (2FA)</a> enabled on your Kirby installation.',
          theme: 'notice',
          width: '2/3'
        },
        scheduling_auth_spacer: {
          type: 'info',
          text: ' ',
          theme: 'none',
          width: '1/3'
        },
        auth_enabled: {
          label: 'Enable Token Authentication',
          type: 'toggle',
          text: ['Disabled', 'Enabled'],
          width: '1/6',
          help: 'Use a token for authentication instead of Basic Auth.'
        },
        auth_token: {
          label: 'API Token',
          type: 'text',
          help: 'This token will be used in the X-POSSE-Token header for your cron jobs, as shown in the commands below.',
          width: '1/2',
          validate: {
            minLength: 8
          },
          messages: {
            minLength: 'The token must be at least 8 characters long'
          },
          when: {
            auth_enabled: true
          }
        },
        auth_token_spacer: {
          type: 'info',
          text: ' ',
          theme: 'none',
          width: '1/3',
          when: {
            auth_enabled: true
          }
        },
        scheduling_command_label: {
          label: 'Cron Job Command',
          type: 'headline',
          numbered: false,
          width: '1/1'
        },
        scheduling_command_info: {
          type: 'info',
          text: `<div style="font-family: 'Courier New', Courier, monospace; background-color: #222; color: white; padding: 12px; border-radius: 4px; white-space: pre-wrap; display: block;">
# Option 1: Using Basic Auth (replace USERNAME and PASSWORD with your Kirby panel credentials)
*/10 * * * * curl -s -u "USERNAME:PASSWORD" ${window.location.origin}/api/posse/cron-syndicate > /dev/null 2>&1

# Option 2: Using API Token (replace YOUR-TOKEN with your configured token)
*/10 * * * * curl -s -H "X-POSSE-Token: YOUR-TOKEN" ${window.location.origin}/api/posse/cron-syndicate > /dev/null 2>&1
</div>`,
          theme: 'none',
          width: '2/3'
        },
        scheduling_monitoring_command_label: {
          label: 'Cron Job with Monitoring',
          type: 'headline',
          numbered: false,
          width: '1/1'
        },
        scheduling_monitoring: {
          type: 'info',
          text: '<strong>Pro Tip:</strong> Monitor your cron jobs with a service like <a href="https://healthchecks.io" target="_blank">Healthchecks.io</a> to ensure they\'re running properly. This helps you detect and fix issues before they affect your content syndication.',
          theme: 'info',
          width: '2/3'
        },
        scheduling_monitoring_command_info: {
          type: 'info',
          text: `<div style="font-family: 'Courier New', Courier, monospace; background-color: #222; color: white; padding: 12px; border-radius: 4px; white-space: pre-wrap; display: block;">
# Option 1: Using Basic Auth (replace USERNAME, PASSWORD, and YOUR-UUID with your values)
*/10 * * * * curl -s -u "USERNAME:PASSWORD" ${window.location.origin}/api/posse/cron-syndicate && curl -fsS -m 10 https://hc-ping.com/YOUR-UUID > /dev/null 2>&1

# Option 2: Using API Token (replace YOUR-TOKEN and YOUR-UUID with your values)
*/10 * * * * curl -s -H "X-POSSE-Token: YOUR-TOKEN" ${window.location.origin}/api/posse/cron-syndicate && curl -fsS -m 10 https://hc-ping.com/YOUR-UUID > /dev/null 2>&1
</div>`,
          theme: 'none',
          width: '2/3'
        },

        // Mastodon Settings
        mastodon_headline: {
          label: 'Mastodon',
          type: 'headline',
          width: '1/1'
        },
        mastodon_enabled: {
          label: 'Enable Mastodon',
          type: 'toggle',
          text: ['Disabled', 'Enabled'],
          width: '1/6'
        },
        mastodon_instance_url: {
          label: 'Instance URL',
          type: 'url',
          placeholder: 'https://mastodon.social',
          width: '1/2',
          when: {
            mastodon_enabled: true
          }
        },
        mastodon_api_token: {
          label: 'API Token',
          type: 'text',
          width: '1/2',
          help: 'Go to the <a href="https://docs.joinmastodon.org/client/token/" target="_blank">developer settings</a> of your Mastodon account, set up an application with write:media and write:statuses scopes, and use that API token.',
          when: {
            mastodon_enabled: true
          }
        },
        mastodon_image_limit: {
          label: 'Image Limit',
          type: 'number',
          min: 1,
          max: 10,
          default: 4,
          width: '1/6',
          help: 'Maximum number of images to include in syndicated posts',
          required: true,
          when: {
            mastodon_enabled: true
          }
        },

        // Bluesky Settings
        bluesky_headline: {
          label: 'Bluesky',
          type: 'headline',
          width: '1/1'
        },
        bluesky_enabled: {
          label: 'Enable Bluesky',
          type: 'toggle',
          text: ['Disabled', 'Enabled'],
          width: '1/6'
        },
        bluesky_instance_url: {
          label: 'Instance URL',
          type: 'url',
          placeholder: 'https://bsky.social',
          width: '1/2',
          when: {
            bluesky_enabled: true
          }
        },
        bluesky_api_token: {
          label: 'API Token',
          type: 'text',
          width: '1/2',
          help: 'In the <a href="https://bsky.app/settings/app-passwords" target="_blank">app passwords</a> section of your Bluesky account, create a new app password and enter it in the format "username.bsky.social:password".',
          when: {
            bluesky_enabled: true
          }
        },
        bluesky_image_limit: {
          label: 'Image Limit',
          type: 'number',
          min: 1,
          max: 10,
          default: 4,
          width: '1/6',
          help: 'Maximum number of images to include in syndicated posts',
          required: true,
          when: {
            bluesky_enabled: true
          }
        },
      }
    };
  },
  created() {
    this.fetchSettings();
  },
  methods: {
    async fetchSettings() {
      this.loading = true;
      try {
        const csrfToken = this.csrf || "";
        
        // Use fetch API with CSRF token
        const response = await fetch('/api/posse/settings', {
          method: 'GET',
          headers: {
            'X-CSRF': csrfToken
          }
        });
        
        if (!response.ok) {
          throw new Error(`Error loading settings: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Map from API structure to form data
        this.formData = {
          contenttypes: this.mapContentTypes(data.contenttypes),
          syndication_delay: data.syndication_delay || 60,
          auth_enabled: data.auth?.enabled || false,
          auth_token: data.auth?.token || '',
          
          // Mastodon settings
          mastodon_enabled: data.services?.mastodon?.enabled || false,
          mastodon_instance_url: data.services?.mastodon?.instance_url || '',
          mastodon_api_token: data.services?.mastodon?.api_token || '',
          mastodon_image_limit: data.services?.mastodon?.image_limit || 4,
          
          // Bluesky settings
          bluesky_enabled: data.services?.bluesky?.enabled || false,
          bluesky_instance_url: data.services?.bluesky?.instance_url || '',
          bluesky_api_token: data.services?.bluesky?.api_token || '',
          bluesky_image_limit: data.services?.bluesky?.image_limit || 4,
          use_original_image_size: data.use_original_image_size ?? false,
          image_preset: data.image_preset ?? '1800w',
        };
        
        // Update image preset options dynamically from API
        if (data.thumbs_presets && Array.isArray(data.thumbs_presets) && data.thumbs_presets.length > 0) {
          // Update the select field options
          this.formFields.image_preset.options = data.thumbs_presets.map(preset => ({
            value: preset.value,  // Store just the preset name
            text: preset.text
          }));
        } else {
          // Fallback to default options if no presets found
          this.formFields.image_preset.options = [
            { text: 'Width: 300px', value: '300w' },
            { text: 'Width: 600px', value: '600w' },
            { text: 'Width: 900px', value: '900w' },
            { text: 'Width: 1200px', value: '1200w' },
            { text: 'Width: 1800px', value: '1800w' },
            { text: 'Square: 300×300px', value: 'square-300w' },
            { text: 'Square: 600×600px', value: 'square-600w' },
            { text: 'Square: 900×900px', value: 'square-900w' },
            { text: 'Square: 1200×1200px', value: 'square-1200w' },
            { text: 'Square: 1800×1800px', value: 'square-1800w' }
          ];
        }
      } catch (error) {
        console.error("Error loading settings:", error);
        this.$store.dispatch("notification/error", "Failed to load settings");
      } finally {
        this.loading = false;
      }
    },
    
    async saveSettings() {
      try {
        // Map form data back to API structure
        const apiData = {
          services: {
            mastodon: {
              enabled: this.formData.mastodon_enabled,
              instance_url: this.formData.mastodon_instance_url,
              api_token: this.formData.mastodon_api_token,
              image_limit: parseInt(this.formData.mastodon_image_limit) || 4
            },
            bluesky: {
              enabled: this.formData.bluesky_enabled,
              instance_url: this.formData.bluesky_instance_url,
              api_token: this.formData.bluesky_api_token,
              image_limit: parseInt(this.formData.bluesky_image_limit) || 4
            }
          },
          contenttypes: this.parseContentTypes(this.formData.contenttypes),
          syndication_delay: parseInt(this.formData.syndication_delay) || 60,
          use_original_image_size: this.formData.use_original_image_size,
          image_preset: this.formData.image_preset,
          auth: {
            token: this.formData.auth_token,
            enabled: this.formData.auth_enabled
          }
        };
        
        // Use CSRF token from props
        const csrfToken = this.csrf || "";
        
        // Save settings via API
        const response = await fetch('/api/posse/settings', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF': csrfToken
          },
          body: JSON.stringify(apiData)
        });
        
        const data = await response.json();
        
        if (!response.ok || data.status === 'error') {
          throw new Error(data.message || `Failed to save settings: ${response.status}`);
        }
        
        // Show success message
        this.$store.dispatch("notification/success", "Settings saved successfully");
      } catch (error) {
        console.error("Error saving settings:", error);
        this.$store.dispatch("notification/error", error.message);
      }
    },
    
    insertDefaultTemplate() {
      this.formData.template = '{{title}}\n\n{{url}}\n\n{{tags}}';
    },
    
    // Helper to map content types object to comma-separated string
    mapContentTypes(contentTypesObj) {
      if (!contentTypesObj || Object.keys(contentTypesObj).length === 0) return '';
      
      const enabledTypes = [];
      for (const [type, enabled] of Object.entries(contentTypesObj)) {
        if (enabled) {
          enabledTypes.push(type);
        }
      }
      
      return enabledTypes.join(', ');
    },
    
    // Helper to parse comma-separated string to content types object
    parseContentTypes(contentTypesStr) {
      const result = {};
      
      // If no content types provided, return empty object
      if (!contentTypesStr) {
        return {};
      }
      
      // Split by comma and trim whitespace
      const types = contentTypesStr.split(',').map(type => type.trim());
      
      // Set each content type to true
      for (const type of types) {
        if (type) {
          result[type] = true;
        }
      }
      
      return result;
    }
  }
};
</script>