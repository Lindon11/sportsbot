# Core Web APP OS

## Overview

Core Web APP OS is the foundation that powers **PBBG Vault** - a SaaS platform and marketplace for Persistent Browser-Based Game (PBBG) developers. It provides essential functionality for user management, authentication, plugin system, and administrative tools.

## Architecture

### Core Components

```
backend/
├── app/
│   ├── Core/                    # Core platform
│   │   ├── Console/            # Console commands
│   │   ├── Contracts/          # Interfaces (PluginInterface, PluginLifecycleInterface)
│   │   ├── Events/             # Event classes
│   │   ├── Exceptions/         # Exception handlers
│   │   ├── Facades/            # Laravel facades (Hook)
│   │   ├── Helpers/            # Helper functions
│   │   ├── Http/               # Controllers, Middleware, Requests
│   │   ├── Lifecycle/          # Application lifecycle hooks
│   │   ├── Middleware/         # Custom middleware
│   │   ├── Models/             # Eloquent models
│   │   ├── Pipeline/           # Processing pipelines
│   │   ├── Policies/           # Authorization policies
│   │   ├── Providers/          # Service providers
│   │   ├── Services/           # Business logic services
│   │   └── Traits/             # Reusable traits
│   │
│   ├── Plugins/                # Plugin infrastructure only
│   │   ├── Plugin.php          # Base plugin class
│   │   └── README.md           # Plugin development guide
│   │
│   └── Facades/
│       └── Hook.php            # Hook facade for plugins
│
├── config/
│   ├── plugin_schema.php       # Plugin validation schema
│   └── plugins.php             # Plugin configuration
│
├── routes/
│   ├── api.php                 # Core API routes
│   ├── admin.php               # Admin routes
│   └── web.php                 # Web routes
│
└── database/
    └── migrations/             # Core migrations only
```

## Core Features

### Authentication & Authorization
- User registration and login
- Two-Factor Authentication (2FA)
- OAuth integration (Google, GitHub, etc.)
- Password reset functionality
- Role-based access control (RBAC)
- Permission management

### Plugin System
- Plugin installation/uninstallation
- Plugin enable/disable
- Plugin import/export as bundles
- Dynamic route registration
- Hook system for extensibility
- Plugin metadata management

### User Management
- User profiles
- User administration panel
- Ban/unban functionality
- IP banning
- Activity logging

### Administrative Tools
- Dashboard with statistics
- Error log management
- System health monitoring
- Cache management
- Backup management
- Webhook management
- API key management

### Communication
- WebSocket support
- Real-time notifications
- Staff chat
- Admin notifications

### Licensing
- License key generation
- License validation
- Domain binding
- Expiration checking

## Core Migrations

The following database tables are created by core migrations:

| Table | Purpose |
|-------|---------|
| users | User accounts |
| cache | Application cache |
| jobs | Queue jobs |
| personal_access_tokens | API tokens |
| roles | User roles |
| permissions | Role permissions |
| settings | Application settings |
| notifications | User notifications |
| activity_logs | Activity tracking |
| error_logs | Error tracking |
| installed_plugins | Plugin registry |
| plugin_metadata | Plugin metadata |
| license_keys | License management |
| oauth_providers | OAuth connections |
| webhooks | Webhook management |
| api_keys | API key management |
| email_settings | Email configuration |
| email_templates | Email templates |
| admin_notifications | Admin alerts |
| staff_chat_messages | Staff communication |
| ip_bans | IP bans |
| player_bans | User bans |
| user_timers | Timer management |
| player_profiles | User profiles |
| locations | Game locations (configurable) |
| ticket_categories | Support ticket categories |
| tickets | Support tickets |
| ticket_messages | Ticket messages |
| faq_categories | FAQ categories |
| faqs | FAQ entries |
| configurable_types | Extensible type system |

## API Endpoints

### Authentication (`/api/v1/auth`)
- `POST /register` - User registration
- `POST /login` - User login
- `POST /logout` - User logout
- `POST /forgot-password` - Password reset request
- `POST /reset-password` - Password reset

### User (`/api/v1/user`)
- `GET /user` - Current user info
- `POST /user/change-password` - Change password
- `POST /user/username` - Update username

### Two-Factor Auth (`/api/v1/2fa`)
- `GET /status` - 2FA status
- `POST /setup` - Setup 2FA
- `POST /confirm` - Confirm 2FA
- `POST /disable` - Disable 2FA

### OAuth (`/api/v1/oauth`)
- `GET /providers` - Available providers
- `GET /{provider}/redirect` - OAuth redirect
- `GET /{provider}/callback` - OAuth callback

### Plugins (`/api/v1/plugins`)
- `GET /enabled` - List enabled plugins

### Admin (`/api/v1/admin`)
- Plugin management
- User management
- Settings management
- License management
- System administration
- And more...

### WebSocket (`/api/v1/ws`)
- `POST /auth` - Channel authorization
- `POST /poll` - Long polling
- `GET /online-count` - Online users count
- `POST /heartbeat` - Connection heartbeat

## Plugin Development

### Creating a Plugin

1. Create a plugin using the CLI:
```bash
php artisan hub:create MyPlugin
```

2. Or manually create the directory structure:
```
app/Plugins/MyPlugin/
├── plugin.json           # Plugin manifest
├── MyPluginPlugin.php    # Main plugin class
├── routes/
│   ├── api.php          # API routes
│   ├── web.php          # Web routes
│   └── admin.php        # Admin routes
├── Controllers/
├── Models/
├── database/
│   └── migrations/
└── hooks.php            # Hook registrations
```

### Plugin Manifest (plugin.json)

```json
{
    "name": "My Plugin",
    "slug": "my-plugin",
    "version": "1.0.0",
    "description": "A sample plugin",
    "author": "Your Name",
    "requires": {
        "plugins": []
    },
    "routes": {
        "api": true,
        "web": true,
        "admin": false
    },
    "settings": {
        "icon": "mdi-puzzle",
        "color": "#42b883",
        "menu": {
            "enabled": true,
            "section": "main",
            "order": 100
        }
    },
    "permissions": [],
    "hooks": []
}
```

### Exporting a Plugin

```bash
php artisan hub:export my-plugin
```

### Importing a Plugin

```bash
php artisan hub:import /path/to/my-plugin-bundle.zip --enable
```

## Gaming Plugins Bundle

The PBBG Gaming Bundle contains 39 gaming-specific plugins:

| Plugin | Description |
|--------|-------------|
| Achievements | Achievement tracking system |
| AdvancedCrimes | Complex crime mechanics |
| Alliances | Alliance/guild system |
| Announcements | Admin announcements |
| Bank | Banking system |
| Bounty | Bounty hunting |
| Bullets | Bullet/ammunition system |
| Casino | Casino games |
| Chat | Real-time chat |
| Combat | Combat system |
| Crimes | Crime activities |
| DailyRewards | Daily login rewards |
| Detective | Detective hire system |
| Drugs | Drug dealing system |
| Education | Education/courses |
| Employment | Job system |
| Events | Game events |
| Forum | Forum system |
| Gang | Gang management |
| Hospital | Healing system |
| Inventory | Item inventory |
| Jail | Jail system |
| Leaderboards | Rankings |
| Market | Player market |
| Messaging | Private messages |
| MiniRpg | Mini RPG system |
| Missions | Mission/quest system |
| OrganizedCrime | Group crimes |
| Progression | Level/rank system |
| Properties | Property ownership |
| Quests | Quest system |
| Racing | Racing mini-game |
| Stocks | Stock market |
| Theft | Theft mechanics |
| Tickets | Support tickets |
| Tournament | Tournaments |
| Travel | Location travel |
| Wiki | Wiki/article system |

### Installing the Gaming Bundle

1. Download `pbbg-gaming-bundle-v1.0.0.zip`
2. Extract individual plugin bundles
3. Import each plugin:
```bash
php artisan hub:import /path/to/plugin-bundle.zip --enable
```

## License Tiers

| Tier | Price | Features |
|------|-------|----------|
| Standard | $49 | Single domain, unlimited plugins, community support |
| Extended | $149 | 3 domains, unlimited plugins, priority support |
| Unlimited | $299 | Unlimited domains, white-label, dedicated support |

## Installation

1. Clone the repository
2. Install dependencies:
```bash
composer install
npm install
```
3. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```
4. Run migrations:
```bash
php artisan migrate
```
5. Create admin user:
```bash
php artisan db:seed --class=DefaultAdminSeeder
```
6. Build frontend:
```bash
npm run build
```

## Support

For support, please open an issue on GitHub or contact support@pbbgvault.dev
