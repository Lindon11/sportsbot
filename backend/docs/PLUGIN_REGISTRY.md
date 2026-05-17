# Plugin Registry Design

## Purpose
The PluginRegistry is the single source of truth for all plugins in the system. It is responsible for:
- Discovering all plugins in the plugins directory
- Validating each plugin's plugin.json against the contract
- Storing and exposing plugin metadata:
  - enabled/disabled state
  - version
  - permissions
  - compatibility (LaravelCP version)

This enables:
- Safe installs and upgrades
- Version checks
- Permission enforcement
- Future marketplace integration

---

## Responsibilities

1. **Discovery**
   - Scan the plugins directory for all folders matching the required structure
   - Ignore or reject folders that do not match

2. **Validation**
   - Validate each plugin.json against the locked schema in PLUGIN_CONTRACT.md
   - Reject plugins with missing or invalid fields

3. **State Management**
   - Track which plugins are enabled or disabled
   - Store plugin version, permissions, and compatibility info

4. **API**
   - Provide methods to:
     - List all plugins
     - Get plugin details (version, permissions, etc.)
     - Enable/disable plugins
     - Check compatibility

---

## Example (PHP, pseudo-code)

```php
class PluginRegistry
{
    protected $plugins = [];

    public function loadPlugins($directory)
    {
        foreach (glob($directory . '/*/plugin.json') as $jsonFile) {
            $plugin = json_decode(file_get_contents($jsonFile), true);
            if ($this->validate($plugin)) {
                $slug = $plugin['slug'];
                $this->plugins[$slug] = [
                    'enabled' => $this->isEnabled($slug),
                    'version' => $plugin['version'],
                    'permissions' => $plugin['permissions'],
                    'requires' => $plugin['requires'],
                    // ...other fields
                ];
            }
        }
    }

    public function validate($plugin)
    {
        // Validate against PLUGIN_CONTRACT.md schema
        // Return true if valid, false otherwise
    }

    public function isEnabled($slug)
    {
        // Check config or DB for enabled/disabled state
    }

    public function get($slug)
    {
        return $this->plugins[$slug] ?? null;
    }

    public function all()
    {
        return $this->plugins;
    }
}
```

---

## Notes
- The registry should be loaded on application boot.
- All plugin actions (install, enable, upgrade) must go through the registry.
- This registry does not require hooks yet—just metadata and state.

---

**This registry is critical for safe plugin management and future extensibility.**
