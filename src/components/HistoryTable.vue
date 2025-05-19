<template>
  <section class="k-section">
    <!-- Table header section -->
    <header class="k-section-header">
      <h2 class="k-label k-section-label" title="History">
        <span class="k-label-text">History</span>
      </h2>
    </header>

    <!-- Kirby styled table -->
    <div class="k-table">
      <table>
        <thead>
          <tr>
            <th data-column-id="page" data-mobile="true" class="k-table-column" style="width: 40%;">Page</th>
            <th data-column-id="service" class="k-table-column" style="width: 10%;">Service</th>
            <th data-column-id="syndicated_at" class="k-table-column" style="width: 20%;">Syndicated At</th>
            <th data-column-id="status" data-mobile="true" class="k-table-column" style="width: 10%;">Status</th>
            <th data-column-id="actions" data-mobile="true" class="k-table-column" style="width: 20%;">Actions</th>
          </tr>
        </thead>
        <tbody class="k-draggable" v-if="items && filteredItems.length > 0">
          <tr v-for="item in filteredItems" :key="item.id">
            <!-- Page title with link and published date -->
            <td data-column-id="page" data-mobile="true" class="k-table-cell k-table-column" style="width: 40%; vertical-align: middle;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; align-items: flex-start;">
                <p :data-link="item.page_url" class="k-url-field-preview" style="padding-bottom: 0; margin-bottom: 0; width: 100%;">
                  <a :href="item.page_url" class="k-link" target="_blank">
                    <span>{{ item.page_title }}</span>
                  </a>
                </p>
                <p class="k-text-field-preview" style="color: var(--color-gray-500); font-size: 0.85em; margin-top: 0.1rem; margin-bottom: 0; padding-bottom: 0;">
                  Published: {{ formatDateTime(item.published_at || item.published_time) }} | 
                  <a :href="item.panel_url || '#'" class="k-link posse-panel-link" target="_blank">
                    Open in panel
                  </a>
                </p>
              </div>
            </td>
            
            <!-- Service -->
            <td data-column-id="service" class="k-table-cell k-table-column" style="width: 10%; vertical-align: middle;">
              <div style="padding: 0.5rem 0.75rem; display: flex; justify-content: flex-start;">
                <span v-if="item.service === 'mastodon'" title="Mastodon">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M11.19 12.195c2.016-.24 3.77-1.475 3.99-2.603.348-1.778.32-4.339.32-4.339 0-3.47-2.286-4.488-2.286-4.488C12.062.238 10.083.017 8.027 0h-.05C5.92.017 3.942.238 2.79.765c0 0-2.285 1.017-2.285 4.488l-.002.662c-.004.64-.007 1.35.011 2.091.083 3.394.626 6.74 3.78 7.57 1.454.383 2.703.463 3.709.408 1.823-.1 2.847-.647 2.847-.647l-.06-1.317s-1.303.41-2.767.36c-1.45-.05-2.98-.156-3.215-1.928a3.614 3.614 0 0 1-.033-.496s1.424.346 3.228.428c1.103.05 2.137-.064 3.188-.189zm1.613-2.47H11.13v-4.08c0-.859-.364-1.295-1.091-1.295-.804 0-1.207.517-1.207 1.541v2.233H7.168V5.89c0-1.024-.403-1.541-1.207-1.541-.727 0-1.091.436-1.091 1.296v4.079H3.197V5.522c0-.859.22-1.541.66-2.046.456-.505 1.052-.764 1.793-.764.856 0 1.504.328 1.933.983L8 4.39l.417-.695c.429-.655 1.077-.983 1.934-.983.74 0 1.336.259 1.791.764.442.505.661 1.187.661 2.046v4.203z"/>
                  </svg>
                </span>
                <span v-else-if="item.service === 'bluesky'" title="Bluesky">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-bluesky" viewBox="0 0 16 16">
                    <path d="M3.468 1.948C5.303 3.325 7.276 6.118 8 7.616c.725-1.498 2.698-4.29 4.532-5.668C13.855.955 16 .186 16 2.632c0 .489-.28 4.105-.444 4.692-.572 2.04-2.653 2.561-4.504 2.246 3.236.551 4.06 2.375 2.281 4.2-3.376 3.464-4.852-.87-5.23-1.98-.07-.204-.103-.3-.103-.218 0-.081-.033.014-.102.218-.379 1.11-1.855 5.444-5.231 1.98-1.778-1.825-.955-3.65 2.28-4.2-1.85.315-3.932-.205-4.503-2.246C.28 6.737 0 3.12 0 2.632 0 .186 2.145.955 3.468 1.948"/>
                  </svg>
                </span>
                <span v-else :title="formatService(item.service)">
                  {{ formatService(item.service).charAt(0) }}
                </span>
              </div>
            </td>
            
            <!-- Syndicated/Ignored at date -->
            <td data-column-id="syndicated_at" class="k-table-cell k-table-column" style="width: 20%; vertical-align: middle; text-align: left;">
              <div style="width: 100%; padding: 0.5rem 0.75rem;">
                <p class="k-text-field-preview" style="margin: 0; padding: 0; text-align: left;">
                  {{ formatDateTime(item.syndicated_at || item.syndication_time) }}
                </p>
              </div>
            </td>
            
            <!-- Status badge -->
            <td data-column-id="status" data-mobile="true" class="k-table-cell k-table-column" style="width: 10%; vertical-align: middle;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; justify-content: flex-start;">
                <!-- Syndicated badge - show when item has a syndicated_at value AND is not ignored -->
                <button 
                  v-if="(item.syndicated_at && item.syndicated_at !== 'null' && item.syndicated_at !== '') && item.ignored != 1"
                  data-has-icon="true" 
                  data-has-text="true"
                  data-size="xs" 
                  data-theme="positive" 
                  data-variant="filled" 
                  type="button"
                  class="k-button k-table-update-status-cell-button"
                >
                  <span class="k-button-icon">
                    <svg aria-hidden="true" data-type="check" class="k-icon">
                      <use xlink:href="#icon-check"></use>
                    </svg>
                  </span>
                  <span class="k-button-text">Syndicated</span>
                </button>
                
                <!-- Ignored badge - show ANYTIME ignored=1, regardless of syndicated_at -->
                <button 
                  v-else-if="item.ignored == 1"
                  data-has-icon="true" 
                  data-has-text="true"
                  data-size="xs" 
                  data-theme="disabled" 
                  data-variant="filled" 
                  type="button"
                  class="k-button k-table-update-status-cell-button"
                >
                  <span class="k-button-icon">
                    <svg aria-hidden="true" data-type="cancel" class="k-icon">
                      <use xlink:href="#icon-cancel"></use>
                    </svg>
                  </span>
                  <span class="k-button-text">Ignored</span>
                </button>
              </div>
            </td>
            
            <!-- Actions column -->
            <td data-column-id="actions" data-mobile="true" class="k-table-cell k-table-column" style="width: 20%; vertical-align: middle; text-align: left;">
              <div style="width: 100%; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; justify-content: flex-start; align-items: flex-start; gap: 5px;">
                <!-- View post button for items with a URL -->
                <div v-if="item.syndicated_url && item.syndicated_url !== 'null' && item.syndicated_url !== ''" style="display: flex; flex-direction: column; align-items: flex-start; gap: 5px; width: 100%;">
                  <k-button 
                    icon="url" 
                    text="View Post"
                    :link="item.syndicated_url"
                    target="_blank"
                    size="small"
                    style="width: 100%; justify-content: flex-start;"
                    class="posse-action-button"
                  />
                </div>
                
                <!-- Add to queue button for ignored items -->
                <div v-if="item.ignored == 1" style="display: flex; flex-direction: column; align-items: flex-start; gap: 5px; width: 100%;">
                  <k-button 
                    icon="add" 
                    text="Add to queue"
                    @click="$emit('unignore', item)"
                    size="small"
                    data-theme="info" 
                    style="width: 100%; justify-content: flex-start;"
                    class="posse-action-button"
                  />
                </div>
                
                <!-- Placeholder for items with no actions -->
                <span v-if="!(item.syndicated_url && item.syndicated_url !== 'null' && item.syndicated_url !== '') && item.ignored != 1" class="k-text-field-preview">—</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      
      <!-- Empty history state -->
      <div v-if="!items || !filteredItems || filteredItems.length === 0" class="k-collection-empty posse-empty-state">
        <div v-if="!error && !loading" class="k-empty">
          <p class="posse-empty-description">
            Syndicated and ignored items will appear here once processed.
          </p>
        </div>
        
        <!-- Error State -->
        <div v-else-if="error" class="k-empty">
          <p class="posse-empty-description">{{ error }}</p>
        </div>
        
        <!-- Loading state -->
        <div v-else class="k-empty">
          <p class="posse-empty-description">Loading syndication history...</p>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  props: {
    items: {
      type: Array,
      default: () => []
    },
    loading: {
      type: Boolean,
      default: false
    },
    error: {
      type: [String, null],
      default: null
    }
  },
  computed: {
    // Filter items to include ALL syndicated items and ALL ignored items
    filteredItems() {
      if (!this.items || !Array.isArray(this.items)) return [];
      
      // Use more robust string-based filtering to detect syndicated items or ignored items
      return this.items.filter(item => {
        // Ensure values are strings and check for emptiness and "null" value
        const syndAtStr = String(item.syndicated_at || '');
        const syndTimeStr = String(item.syndication_time || '');
        const isIgnored = item.ignored === true || item.ignored === 1 || item.ignored === "1";
        const hasSyndicatedAt = syndAtStr !== '' && syndAtStr !== 'null' && syndAtStr !== 'undefined';
        const hasSyndicationTime = syndTimeStr !== '' && syndTimeStr !== 'null' && syndTimeStr !== 'undefined';
        
        // Return true in either of these cases:
        // 1. The item has a valid syndicated_at value OR
        // 2. The item is explicitly marked as ignored
        return (hasSyndicatedAt || hasSyndicationTime) || isIgnored;
      });
    }
  },
  methods: {
    formatService(service) {
      if (!service) return '';
      // Capitalize first letter
      return service.charAt(0).toUpperCase() + service.slice(1);
    },
    formatDateTime(dateString) {
      if (!dateString) return '';
      const date = new Date(dateString);
      
      // Format as YYYY-MM-DD HH:MM
      const pad = (num) => String(num).padStart(2, '0');
      const year = date.getFullYear();
      const month = pad(date.getMonth() + 1);
      const day = pad(date.getDate());
      const hours = pad(date.getHours());
      const minutes = pad(date.getMinutes());
      
      return `${year}-${month}-${day} ${hours}:${minutes}`;
    },
  }
};
</script>

<style>
.posse-action-button {
  transition: color 0.2s ease-in-out;
}

.posse-action-button:hover .k-button-text {
  color: var(--color-focus);
  text-decoration: underline;
}

.posse-action-button:hover .k-button-icon {
  color: var(--color-focus);
}

/* Empty state styling */
.posse-empty-state {
  padding: 2rem 0;
}

.posse-empty-state .k-empty {
  padding: 2rem;
}

.posse-empty-description {
  font-size: 1rem;
  color: var(--color-text);
  margin: 1rem 0;
  max-width: 400px;
  margin-left: auto;
  margin-right: auto;
  line-height: 1.5;
  text-align: center;
}

.posse-action-link {
  color: var(--color-focus);
  text-decoration: none;
  font-weight: 500;
  transition: all 0.2s;
}

.posse-action-link:hover {
  text-decoration: underline;
}
</style>