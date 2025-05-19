import './index.css';
import PosseView from './components/PosseView.vue';
import PosseSettings from './components/PosseSettings.vue';

// Make sure panel exists before registering the plugin
if (typeof window.panel !== 'undefined') {
  // Register the plugin
  window.panel.plugin('notmyhostname/posse', {
    // Register our custom view components
    components: {
      'posse-view': PosseView,
      'posse-settings': PosseSettings
    }
  });
}