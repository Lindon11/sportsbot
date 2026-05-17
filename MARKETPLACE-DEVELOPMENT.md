# PBBG Vault Marketplace Development Guide

## Overview

This document outlines the complete development plan for the PBBG Vault Marketplace - a platform for selling Web APP OS licenses, plugins, and bundled packages. The marketplace will be built using the Core Web APP OS as its foundation.

---

## Architecture Overview

### Platform Structure

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         PBBG VAULT MARKETPLACE                               │
│              (Built on Core Web APP OS - YOUR installation)                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐                │
│  │  PRODUCT       │  │  PURCHASE      │  │  LICENSE       │                │
│  │  CATALOG       │  │  SYSTEM        │  │  MANAGEMENT    │                │
│  │                │  │                │  │                │                │
│  │ • Core Engine  │  │ • Checkout     │  │ • Issue        │                │
│  │ • Bundles      │  │ • Cart         │  │ • Revoke       │                │
│  │ • Plugins      │  │ • Orders       │  │ • Suspend      │                │
│  │ • Free/Paid    │  │ • Payment Stub │  │ • Heartbeats   │                │
│  └────────────────┘  └────────────────┘  └────────────────┘                │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │                    ADMIN-ONLY: LICENSE MANAGEMENT                      │ │
│  │                                                                        │ │
│  │  • Issue/Revoke/Suspend Core Engine Licenses                          │ │
│  │  • Issue/Revoke/Suspend Plugin Licenses                               │ │
│  │  • WebSocket Heartbeat Monitoring (5-minute intervals)                │ │
│  │  • Integration with existing LicenseService                           │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │                    THIRD-PARTY VENDOR SYSTEM                           │ │
│  │                                                                        │ │
│  │  • Vendor Registration & Verification                                  │ │
│  │  • Plugin Submission                                                  │ │
│  │  • Sales Dashboard                                                    │ │
│  │  • Revenue Sharing                                                    │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                       CUSTOMER'S INSTALLATION                                │
│            (Downloaded from marketplace, runs independently)                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐                │
│  │  Core Engine   │  │  Purchased     │  │  LICENSE       │                │
│  │  (your code)   │  │  Plugins       │  │  ACTIVATION    │                │
│  └────────────────┘  └────────────────┘  └────────────────┘                │
│         │                    │                    │                         │
│         └────────────────────┴────────────────────┘                         │
│                              │                                               │
│                    WebSocket Heartbeat                                       │
│                    (5-minute intervals to marketplace)                       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Data Flow

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Customer   │────▶│  Marketplace │────▶│   License    │
│   Browser    │     │    Server    │     │   Service    │
└──────────────┘     └──────────────┘     └──────────────┘
       │                    │                     │
       │   1. Browse        │                     │
       │   Products         │                     │
       │ ──────────────────▶│                     │
       │                    │                     │
       │   2. Purchase      │                     │
       │ ──────────────────▶│                     │
       │                    │   3. Generate       │
       │                    │   License Key       │
       │                    │ ───────────────────▶│
       │                    │                     │
       │   4. Download      │                     │
       │ ◀──────────────────│                     │
       │                    │                     │
       │   5. Activate      │                     │
       │ ───────────────────────────────────────▶│
       │                    │                     │
       │   6. Heartbeat     │                     │
       │ ───────────────────────────────────────▶│
       │   (every 5 min)    │                     │
```

---

## Database Schema

### New Tables Required

#### 1. `marketplace_products`

Stores all products available for purchase/download.

```sql
CREATE TABLE marketplace_products (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Basic Info
    name                VARCHAR(255) NOT NULL,
    slug                VARCHAR(255) NOT NULL UNIQUE,
    description         TEXT,
    short_description   VARCHAR(500),
    
    -- Product Type
    type                ENUM('core', 'bundle', 'plugin', 'theme') NOT NULL DEFAULT 'plugin',
    category            VARCHAR(100),  -- 'gameplay', 'economy', 'social', 'admin', etc.
    
    -- Pricing
    price               DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    is_free             BOOLEAN DEFAULT FALSE,
    currency            VARCHAR(3) DEFAULT 'USD',
    
    -- License Requirements
    requires_license    BOOLEAN DEFAULT FALSE,
    license_tier        ENUM('any', 'standard', 'extended', 'unlimited') DEFAULT 'any',
    
    -- Author/Vendor
    author_id           BIGINT UNSIGNED,  -- NULL for official products
    is_official         BOOLEAN DEFAULT TRUE,  -- PBBG Vault official vs third-party
    
    -- Download Info
    download_path       VARCHAR(500),  -- Path to downloadable file
    file_size           BIGINT UNSIGNED,  -- File size in bytes
    version             VARCHAR(20) NOT NULL DEFAULT '1.0.0',
    changelog           TEXT,
    
    -- Metadata
    icon                VARCHAR(50),
    screenshots         JSON,  -- Array of screenshot URLs
    features            JSON,  -- Array of feature strings
    requirements        JSON,  -- {laravel: '^11.0', php: '^8.2', plugins: [...]}
    
    -- Statistics
    downloads_count     INT UNSIGNED DEFAULT 0,
    purchases_count     INT UNSIGNED DEFAULT 0,
    rating_average      DECIMAL(3, 2) DEFAULT 0.00,
    ratings_count       INT UNSIGNED DEFAULT 0,
    
    -- Status
    status              ENUM('draft', 'pending_review', 'published', 'retired') DEFAULT 'draft',
    featured            BOOLEAN DEFAULT FALSE,
    
    -- Timestamps
    published_at        TIMESTAMP NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE,
    deleted_at          TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_author (author_id),
    INDEX idx_featured (featured)
);
```

#### 2. `marketplace_product_versions`

Version history for products.

```sql
CREATE TABLE marketplace_product_versions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          BIGINT UNSIGNED NOT NULL,
    
    version             VARCHAR(20) NOT NULL,
    changelog           TEXT,
    download_path       VARCHAR(500),
    file_size           BIGINT UNSIGNED,
    
    is_latest           BOOLEAN DEFAULT FALSE,
    downloads_count     INT UNSIGNED DEFAULT 0,
    
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_version (product_id, version),
    INDEX idx_latest (product_id, is_latest)
);
```

#### 3. `marketplace_purchases`

Transaction records for all purchases.

```sql
CREATE TABLE marketplace_purchases (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Parties
    user_id             BIGINT UNSIGNED NOT NULL,
    product_id          BIGINT UNSIGNED NOT NULL,
    
    -- Pricing
    price_paid          DECIMAL(10, 2) NOT NULL,
    currency            VARCHAR(3) DEFAULT 'USD',
    
    -- Payment (Stub for now)
    payment_method      VARCHAR(50) DEFAULT 'manual',  -- 'stripe', 'paypal', 'manual'
    payment_status      ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'completed',
    payment_reference   VARCHAR(255),
    
    -- License
    license_id          BIGINT UNSIGNED,  -- Link to generated license
    license_key_id      BIGINT UNSIGNED,  -- Link to license_keys table
    
    -- Download Tracking
    download_count      INT UNSIGNED DEFAULT 0,
    last_download_at    TIMESTAMP NULL,
    
    -- Timestamps
    purchased_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    refunded_at         TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE SET NULL,
    
    INDEX idx_user (user_id),
    INDEX idx_product (product_id),
    INDEX idx_payment_status (payment_status)
);
```

#### 4. `product_licenses`

License records for licensed products.

```sql
CREATE TABLE product_licenses (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Product Reference
    product_id          BIGINT UNSIGNED NOT NULL,
    purchase_id         BIGINT UNSIGNED,
    license_key_id      BIGINT UNSIGNED,  -- Link to license_keys table
    
    -- License Info
    license_key         VARCHAR(255) NOT NULL UNIQUE,
    license_id_short    VARCHAR(8),  -- Short ID for display
    
    -- Owner
    owner_id            BIGINT UNSIGNED NOT NULL,
    owner_email         VARCHAR(255),
    
    -- Status
    status              ENUM('active', 'suspended', 'revoked', 'expired') DEFAULT 'active',
    
    -- Activation
    activated_domain    VARCHAR(255),
    activated_ip        VARCHAR(45),
    activated_at        TIMESTAMP NULL,
    
    -- Heartbeat Tracking
    last_heartbeat_at   TIMESTAMP NULL,
    heartbeat_domain    VARCHAR(255),
    heartbeat_ip        VARCHAR(255),
    heartbeat_version   VARCHAR(20),
    missed_heartbeats   INT UNSIGNED DEFAULT 0,
    
    -- Suspension/Revocation
    suspended_at        TIMESTAMP NULL,
    suspended_reason    TEXT,
    suspended_by        BIGINT UNSIGNED,
    revoked_at          TIMESTAMP NULL,
    revoked_reason      TEXT,
    revoked_by          BIGINT UNSIGNED,
    
    -- Expiry
    expires_at          TIMESTAMP NULL,
    
    -- Timestamps
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE,
    
    FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_owner (owner_id),
    INDEX idx_status (status),
    INDEX idx_key (license_key)
);
```

#### 5. `license_heartbeats`

Heartbeat log for monitoring active installations.

```sql
CREATE TABLE license_heartbeats (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    license_id          BIGINT UNSIGNED NOT NULL,
    
    -- Client Info
    domain              VARCHAR(255),
    ip_address          VARCHAR(45),
    version             VARCHAR(20),
    
    -- System Info (optional)
    php_version         VARCHAR(20),
    laravel_version     VARCHAR(20),
    plugin_count        INT UNSIGNED,
    
    -- Timestamp
    received_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (license_id) REFERENCES product_licenses(id) ON DELETE CASCADE,
    
    INDEX idx_license (license_id),
    INDEX idx_received (received_at)
);
```

#### 6. `marketplace_vendors`

Third-party vendor/seller accounts.

```sql
CREATE TABLE marketplace_vendors (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Account
    user_id             BIGINT UNSIGNED NOT NULL UNIQUE,
    
    -- Vendor Info
    company_name        VARCHAR(255),
    display_name        VARCHAR(100) NOT NULL,
    slug                VARCHAR(100) NOT NULL UNIQUE,
    description         TEXT,
    website             VARCHAR(255),
    
    -- Status
    status              ENUM('pending', 'verified', 'suspended', 'banned') DEFAULT 'pending',
    verified_at         TIMESTAMP NULL,
    
    -- Revenue
    commission_rate     DECIMAL(5, 2) DEFAULT 30.00,  -- PBBG Vault takes 30%
    total_earnings      DECIMAL(10, 2) DEFAULT 0.00,
    pending_payout      DECIMAL(10, 2) DEFAULT 0.00,
    paid_out            DECIMAL(10, 2) DEFAULT 0.00,
    
    -- Timestamps
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_status (status),
    INDEX idx_slug (slug)
);
```

#### 7. `marketplace_reviews`

Product reviews and ratings.

```sql
CREATE TABLE marketplace_reviews (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    product_id          BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    purchase_id         BIGINT UNSIGNED,
    
    rating              TINYINT UNSIGNED NOT NULL CHECK (rating >= 1 AND rating <= 5),
    title               VARCHAR(255),
    content             TEXT,
    
    status              ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    
    helpful_count       INT UNSIGNED DEFAULT 0,
    
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE,
    
    FOREIGN KEY (product_id) REFERENCES marketplace_products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_user_product_review (user_id, product_id),
    INDEX idx_product (product_id),
    INDEX idx_rating (rating)
);
```

---

## Backend Implementation

### New Models

#### `app/Core/Models/MarketplaceProduct.php`

```php
<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketplaceProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'short_description',
        'type', 'category',
        'price', 'is_free', 'currency',
        'requires_license', 'license_tier',
        'author_id', 'is_official',
        'download_path', 'file_size', 'version', 'changelog',
        'icon', 'screenshots', 'features', 'requirements',
        'downloads_count', 'purchases_count', 'rating_average', 'ratings_count',
        'status', 'featured', 'published_at',
    ];

    protected $casts = [
        'is_free' => 'boolean',
        'requires_license' => 'boolean',
        'is_official' => 'boolean',
        'featured' => 'boolean',
        'price' => 'decimal:2',
        'rating_average' => 'decimal:2',
        'screenshots' => 'array',
        'features' => 'array',
        'requirements' => 'array',
        'published_at' => 'datetime',
    ];

    // Relationships
    public function author() { return $this->belongsTo(User::class, 'author_id'); }
    public function purchases() { return $this->hasMany(MarketplacePurchase::class, 'product_id'); }
    public function licenses() { return $this->hasMany(ProductLicense::class, 'product_id'); }
    public function reviews() { return $this->hasMany(MarketplaceReview::class, 'product_id'); }
    public function versions() { return $this->hasMany(MarketplaceProductVersion::class, 'product_id'); }

    // Scopes
    public function scopePublished($query) { return $query->where('status', 'published'); }
    public function scopeFree($query) { return $query->where('is_free', true); }
    public function scopePaid($query) { return $query->where('is_free', false); }
    public function scopeOfficial($query) { return $query->where('is_official', true); }
    public function scopePlugins($query) { return $query->where('type', 'plugin'); }
    public function scopeBundles($query) { return $query->where('type', 'bundle'); }
    public function scopeCore($query) { return $query->where('type', 'core'); }

    // Helpers
    public function isOwnedBy(User $user): bool
    {
        return $this->purchases()->where('user_id', $user->id)->exists();
    }

    public function canBeDownloadedBy(User $user): bool
    {
        if ($this->is_free) return true;
        return $this->isOwnedBy($user);
    }
}
```

#### `app/Core/Models/ProductLicense.php`

```php
<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ProductLicense extends Model
{
    protected $fillable = [
        'product_id', 'purchase_id', 'license_key_id',
        'license_key', 'license_id_short',
        'owner_id', 'owner_email',
        'status',
        'activated_domain', 'activated_ip', 'activated_at',
        'last_heartbeat_at', 'heartbeat_domain', 'heartbeat_ip', 'heartbeat_version',
        'missed_heartbeats',
        'suspended_at', 'suspended_reason', 'suspended_by',
        'revoked_at', 'revoked_reason', 'revoked_by',
        'expires_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'suspended_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function product() { return $this->belongsTo(MarketplaceProduct::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function heartbeats() { return $this->hasMany(LicenseHeartbeat::class, 'license_id'); }
    public function purchase() { return $this->belongsTo(MarketplacePurchase::class); }

    // Status Checks
    public function isActive(): bool { return $this->status === 'active'; }
    public function isSuspended(): bool { return $this->status === 'suspended'; }
    public function isRevoked(): bool { return $this->status === 'revoked'; }
    public function isExpired(): bool 
    { 
        return $this->expires_at && $this->expires_at->isPast(); 
    }

    // Valid license = active + not expired
    public function isValid(): bool
    {
        return $this->isActive() && !$this->isExpired();
    }

    // Scopes
    public function scopeActive($query) { return $query->where('status', 'active'); }
    public function scopeSuspended($query) { return $query->where('status', 'suspended'); }
    public function scopeRevoked($query) { return $query->where('status', 'revoked'); }
}
```

### New Services

#### `app/Core/Services/MarketplaceService.php`

```php
<?php

namespace App\Core\Services;

use App\Core\Models\MarketplaceProduct;
use App\Core\Models\User;
use Illuminate\Support\Facades\Storage;

class MarketplaceService
{
    /**
     * Get published products with filters
     */
    public function getProducts(array $filters = [])
    {
        $query = MarketplaceProduct::published()
            ->with('author');

        // Type filter
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Category filter
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // Price filter
        if (isset($filters['free']) && $filters['free']) {
            $query->where('is_free', true);
        }
        if (isset($filters['paid']) && $filters['paid']) {
            $query->where('is_free', false);
        }

        // Search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $filters['sort'] ?? 'downloads';
        $query->orderBy($sortBy, $sortBy === 'name' ? 'asc' : 'desc');

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get featured products
     */
    public function getFeatured(int $limit = 6): array
    {
        return MarketplaceProduct::published()
            ->where('featured', true)
            ->orderBy('downloads_count', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get product details
     */
    public function getProduct(string $slug): ?MarketplaceProduct
    {
        return MarketplaceProduct::where('slug', $slug)
            ->with(['author', 'reviews.user', 'versions'])
            ->first();
    }

    /**
     * Get categories with product counts
     */
    public function getCategories(): array
    {
        return MarketplaceProduct::published()
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->get()
            ->toArray();
    }
}
```

#### `app/Core/Services/PurchaseService.php`

```php
<?php

namespace App\Core\Services;

use App\Core\Models\MarketplaceProduct;
use App\Core\Models\MarketplacePurchase;
use App\Core\Models\ProductLicense;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseService
{
    protected LicenseService $licenseService;

    public function __construct(LicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * Purchase a product (creates purchase record and license if needed)
     */
    public function purchase(User $user, MarketplaceProduct $product): MarketplacePurchase
    {
        return DB::transaction(function () use ($user, $product) {
            // Create purchase record
            $purchase = MarketplacePurchase::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'price_paid' => $product->price,
                'currency' => $product->currency,
                'payment_status' => $product->is_free ? 'completed' : 'pending',
            ]);

            // Generate license if product requires one
            if ($product->requires_license && !$product->is_free) {
                $license = $this->generateLicense($product, $user, $purchase);
                $purchase->update(['license_id' => $license->id]);
            }

            // Update product stats
            $product->increment('purchases_count');

            return $purchase;
        });
    }

    /**
     * Generate a license key for a product
     */
    protected function generateLicense(
        MarketplaceProduct $product,
        User $user,
        MarketplacePurchase $purchase
    ): ProductLicense {
        // Use existing LicenseService to generate RSA-signed key
        $licenseKey = LicenseService::generate([
            'domain' => '*',
            'tier' => $product->license_tier,
            'customer' => $user->name,
            'email' => $user->email,
            'expires' => 'lifetime',
            'max_users' => 0,
            'plugins' => $product->slug,
        ]);

        return ProductLicense::create([
            'product_id' => $product->id,
            'purchase_id' => $purchase->id,
            'license_key' => $licenseKey,
            'license_id_short' => strtoupper(Str::random(8)),
            'owner_id' => $user->id,
            'owner_email' => $user->email,
            'status' => 'active',
        ]);
    }

    /**
     * Get user's purchases
     */
    public function getUserPurchases(User $user)
    {
        return MarketplacePurchase::where('user_id', $user->id)
            ->with(['product', 'license'])
            ->orderBy('purchased_at', 'desc')
            ->paginate();
    }

    /**
     * Generate download token for a product
     */
    public function generateDownloadToken(User $user, MarketplaceProduct $product): string
    {
        if (!$product->canBeDownloadedBy($user)) {
            throw new \Exception('You do not have access to download this product.');
        }

        return \Illuminate\Support\Facades\Cache::put(
            "download_token:{$user->id}:{$product->id}",
            true,
            now()->addMinutes(30)
        );
    }
}
```

#### `app/Core/Services/LicenseHeartbeatService.php`

```php
<?php

namespace App\Core\Services;

use App\Core\Models\ProductLicense;
use App\Core\Models\LicenseHeartbeat;
use Illuminate\Support\Facades\Log;

class LicenseHeartbeatService
{
    const HEARTBEAT_INTERVAL = 300; // 5 minutes in seconds
    const MAX_MISSED_HEARTBEATS = 3; // Auto-suspend after 3 missed (15 minutes)

    /**
     * Process incoming heartbeat
     */
    public function processHeartbeat(
        string $licenseKey,
        string $domain,
        string $ip,
        array $metadata = []
    ): array {
        $license = ProductLicense::where('license_key', $licenseKey)->first();

        if (!$license) {
            return ['success' => false, 'error' => 'License not found'];
        }

        if ($license->isRevoked()) {
            return ['success' => false, 'error' => 'License has been revoked'];
        }

        if ($license->isExpired()) {
            return ['success' => false, 'error' => 'License has expired'];
        }

        // If suspended, check if can be reactivated
        if ($license->isSuspended()) {
            // Allow heartbeat but flag for review
            Log::warning('Heartbeat received for suspended license', [
                'license_id' => $license->id,
                'domain' => $domain,
            ]);
        }

        // Record heartbeat
        LicenseHeartbeat::create([
            'license_id' => $license->id,
            'domain' => $domain,
            'ip_address' => $ip,
            'version' => $metadata['version'] ?? null,
            'php_version' => $metadata['php_version'] ?? null,
            'laravel_version' => $metadata['laravel_version'] ?? null,
            'plugin_count' => $metadata['plugin_count'] ?? null,
        ]);

        // Update license
        $license->update([
            'last_heartbeat_at' => now(),
            'heartbeat_domain' => $domain,
            'heartbeat_ip' => $ip,
            'heartbeat_version' => $metadata['version'] ?? null,
            'missed_heartbeats' => 0, // Reset on successful heartbeat
        ]);

        // If was suspended due to missed heartbeats, reactivate
        if ($license->isSuspended() && str_contains($license->suspended_reason ?? '', 'missed heartbeat')) {
            $license->update([
                'status' => 'active',
                'suspended_at' => null,
                'suspended_reason' => null,
            ]);
        }

        return [
            'success' => true,
            'license_status' => $license->fresh()->status,
        ];
    }

    /**
     * Check for missed heartbeats and suspend licenses
     */
    public function checkMissedHeartbeats(): int
    {
        $threshold = now()->subSeconds(self::HEARTBEAT_INTERVAL * (self::MAX_MISSED_HEARTBEATS + 1));

        $licensesToSuspend = ProductLicense::active()
            ->where('last_heartbeat_at', '<', $threshold)
            ->orWhere(function ($query) use ($threshold) {
                $query->whereNull('last_heartbeat_at')
                      ->where('activated_at', '<', $threshold);
            })
            ->get();

        $suspendedCount = 0;

        foreach ($licensesToSuspend as $license) {
            $license->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspended_reason' => 'Auto-suspended: missed heartbeats',
            ]);

            // Broadcast suspension event
            broadcast(new \App\Core\Events\LicenseSuspended($license));

            $suspendedCount++;
        }

        return $suspendedCount;
    }

    /**
     * Suspend a license manually
     */
    public function suspend(ProductLicense $license, string $reason, int $suspendedBy): bool
    {
        return $license->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_reason' => $reason,
            'suspended_by' => $suspendedBy,
        ]);
    }

    /**
     * Revoke a license permanently
     */
    public function revoke(ProductLicense $license, string $reason, int $revokedBy): bool
    {
        return $license->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by' => $revokedBy,
        ]);
    }

    /**
     * Reactivate a suspended license
     */
    public function reactivate(ProductLicense $license): bool
    {
        return $license->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspended_reason' => null,
            'missed_heartbeats' => 0,
        ]);
    }
}
```

---

## API Routes

### Public Routes

```php
// routes/api.php - Add to v1 group

// Marketplace Public Routes
Route::prefix('marketplace')->group(function () {
    
    // Catalog
    Route::get('/products', [MarketplaceController::class, 'index']);
    Route::get('/products/featured', [MarketplaceController::class, 'featured']);
    Route::get('/products/categories', [MarketplaceController::class, 'categories']);
    Route::get('/products/{slug}', [MarketplaceController::class, 'show']);
    
    // Reviews
    Route::get('/products/{slug}/reviews', [MarketplaceController::class, 'reviews']);
    
});

// License Heartbeat (requires valid license key in header)
Route::post('/license/heartbeat', [LicenseController::class, 'heartbeat'])
    ->middleware('throttle:30,1');
```

### Authenticated Routes

```php
// User's marketplace
Route::middleware('auth:sanctum')->group(function () {
    
    // Purchases
    Route::prefix('purchases')->group(function () {
        Route::get('/', [PurchaseController::class, 'index']);
        Route::post('/', [PurchaseController::class, 'store']);
        Route::get('/{id}', [PurchaseController::class, 'show']);
    });
    
    // User's Licenses
    Route::prefix('my-licenses')->group(function () {
        Route::get('/', [UserLicenseController::class, 'index']);
        Route::get('/{id}', [UserLicenseController::class, 'show']);
    });
    
    // Downloads
    Route::prefix('downloads')->group(function () {
        Route::get('/', [DownloadController::class, 'index']);
        Route::post('/token', [DownloadController::class, 'generateToken']);
        Route::get('/{slug}', [DownloadController::class, 'download'])
            ->middleware('signed');
    });
    
    // Reviews
    Route::post('/products/{slug}/review', [MarketplaceController::class, 'storeReview']);
    
    // Vendor Routes (if user is a vendor)
    Route::prefix('vendor')->middleware('vendor')->group(function () {
        Route::get('/dashboard', [VendorController::class, 'dashboard']);
        Route::get('/products', [VendorController::class, 'products']);
        Route::post('/products', [VendorController::class, 'store']);
        Route::put('/products/{id}', [VendorController::class, 'update']);
    });
});
```

### Admin Routes

```php
// Admin marketplace management
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    
    // Products Management
    Route::prefix('marketplace/products')->group(function () {
        Route::get('/', [Admin\MarketplaceManagementController::class, 'index']);
        Route::post('/', [Admin\MarketplaceManagementController::class, 'store']);
        Route::get('/{id}', [Admin\MarketplaceManagementController::class, 'show']);
        Route::put('/{id}', [Admin\MarketplaceManagementController::class, 'update']);
        Route::delete('/{id}', [Admin\MarketplaceManagementController::class, 'destroy']);
        Route::post('/{id}/publish', [Admin\MarketplaceManagementController::class, 'publish']);
        Route::post('/{id}/retire', [Admin\MarketplaceManagementController::class, 'retire']);
    });
    
    // License Management
    Route::prefix('licenses')->group(function () {
        Route::get('/', [Admin\LicenseManagementController::class, 'index']);
        Route::post('/', [Admin\LicenseManagementController::class, 'store']);
        Route::get('/{id}', [Admin\LicenseManagementController::class, 'show']);
        Route::post('/{id}/suspend', [Admin\LicenseManagementController::class, 'suspend']);
        Route::post('/{id}/reactivate', [Admin\LicenseManagementController::class, 'reactivate']);
        Route::post('/{id}/revoke', [Admin\LicenseManagementController::class, 'revoke']);
        Route::get('/{id}/heartbeats', [Admin\LicenseManagementController::class, 'heartbeats']);
    });
    
    // Vendor Management
    Route::prefix('vendors')->group(function () {
        Route::get('/', [Admin\VendorManagementController::class, 'index']);
        Route::post('/{id}/verify', [Admin\VendorManagementController::class, 'verify']);
        Route::post('/{id}/suspend', [Admin\VendorManagementController::class, 'suspend']);
    });
    
    // Heartbeat Dashboard
    Route::get('/heartbeat-stats', [Admin\LicenseManagementController::class, 'heartbeatStats']);
    Route::post('/run-heartbeat-check', [Admin\LicenseManagementController::class, 'runHeartbeatCheck']);
});
```

---

## Frontend Implementation

### New Views

#### `frontend/src/views/MarketplaceView.vue`

Product catalog with search, filters, and categories.

```vue
<template>
  <div class="marketplace">
    <!-- Hero Section -->
    <section class="hero">
      <h1>PBBG Vault Marketplace</h1>
      <p>Plugins, bundles, and themes for your Web APP OS</p>
    </section>

    <!-- Search & Filters -->
    <section class="filters">
      <input v-model="search" placeholder="Search products..." />
      <select v-model="typeFilter">
        <option value="">All Types</option>
        <option value="plugin">Plugins</option>
        <option value="bundle">Bundles</option>
        <option value="theme">Themes</option>
        <option value="core">Core Engine</option>
      </select>
      <select v-model="categoryFilter">
        <option value="">All Categories</option>
        <option v-for="cat in categories" :key="cat.category" :value="cat.category">
          {{ cat.category }} ({{ cat.count }})
        </option>
      </select>
      <label>
        <input type="checkbox" v-model="freeOnly" />
        Free Only
      </label>
    </section>

    <!-- Products Grid -->
    <section class="products-grid">
      <ProductCard
        v-for="product in products.data"
        :key="product.id"
        :product="product"
        @click="viewProduct(product.slug)"
      />
    </section>

    <!-- Pagination -->
    <Pagination :meta="products.meta" @page-change="loadPage" />
  </div>
</template>
```

#### `frontend/src/views/ProductDetailView.vue`

Single product page with purchase/download options.

#### `frontend/src/views/UserLicensesView.vue`

User's license management dashboard.

#### `frontend/src/views/DownloadsView.vue`

Available downloads for the authenticated user.

#### `frontend/src/views/admin/MarketplaceAdminView.vue`

Admin product management interface.

#### `frontend/src/views/admin/LicenseAdminView.vue`

Admin license management with heartbeat monitoring.

### New Components

- `ProductCard.vue` - Product card for grid display
- `LicenseStatusBadge.vue` - Status indicator for licenses
- `HeartbeatChart.vue` - Visual heartbeat timeline
- `DownloadButton.vue` - Secure download button with license check
- `PricingDisplay.vue` - Price display with currency formatting

---

## WebSocket Integration

### New Channel: `license.{id}`

```php
// app/Core/Events/LicenseSuspended.php
class LicenseSuspended implements ShouldBroadcast
{
    public $license;

    public function broadcastOn()
    {
        return new PrivateChannel("license.{$this->license->id}");
    }

    public function broadcastWith()
    {
        return [
            'license_id' => $this->license->id,
            'status' => 'suspended',
            'reason' => $this->license->suspended_reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

### Heartbeat Endpoint Response

```php
// Customer installation sends:
POST /api/v1/license/heartbeat
{
    "license_key": "LCP-STD-...",
    "domain": "customer-site.com",
    "version": "1.0.0",
    "metadata": {
        "php_version": "8.2",
        "laravel_version": "11.0",
        "plugin_count": 5
    }
}

// Marketplace responds:
{
    "success": true,
    "license_status": "active",
    "next_heartbeat": 300,  // seconds
    "server_time": "2026-02-26T11:00:00Z"
}
```

---

## Scheduled Tasks

### Heartbeat Check Command

```php
// app/Core/Console/Commands/CheckLicenseHeartbeats.php
class CheckLicenseHeartbeats extends Command
{
    protected $signature = 'license:check-heartbeats';
    protected $description = 'Check for missed heartbeats and suspend licenses';

    public function handle(LicenseHeartbeatService $service)
    {
        $suspended = $service->checkMissedHeartbeats();
        
        $this->info("Suspended {$suspended} licenses due to missed heartbeats.");
        
        return 0;
    }
}
```

### Schedule in Kernel

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Check heartbeats every 5 minutes
    $schedule->command('license:check-heartbeats')
             ->everyFiveMinutes()
             ->withoutOverlapping();
}
```

---

## File Changes Summary

### New Files to Create

| Category | File Path | Count |
|----------|-----------|-------|
| Migrations | `backend/database/migrations/*_create_marketplace_*.php` | 7 |
| Models | `backend/app/Core/Models/Marketplace*.php`, `ProductLicense.php`, etc. | 7 |
| Services | `backend/app/Core/Services/MarketplaceService.php`, etc. | 4 |
| Controllers | `backend/app/Core/Http/Controllers/MarketplaceController.php`, etc. | 10 |
| Events | `backend/app/Core/Events/LicenseSuspended.php`, etc. | 3 |
| Commands | `backend/app/Core/Console/Commands/CheckLicenseHeartbeats.php` | 1 |
| Views | `frontend/src/views/MarketplaceView.vue`, etc. | 8 |
| Components | `frontend/src/components/marketplace/*.vue` | 5 |
| Stores | `frontend/src/stores/marketplace.ts` | 1 |

### Files to Modify

- `backend/routes/api.php` - Add marketplace routes
- `backend/app/Console/Kernel.php` - Add scheduled task
- `frontend/src/router/index.ts` - Add marketplace routes

---

## Implementation Order

1. **Database** - Create and run migrations
2. **Models** - Create Eloquent models
3. **Services** - Implement business logic
4. **API Routes** - Add controller routes
5. **Frontend Views** - Create Vue components
6. **WebSocket** - Add license channels
7. **Scheduled Tasks** - Configure heartbeat checking
8. **Testing** - Write feature tests

---

## Testing Checklist

- [ ] User can browse products
- [ ] User can view product details
- [ ] User can purchase free product
- [ ] User can purchase paid product (stub)
- [ ] User receives license key for licensed product
- [ ] User can download purchased product
- [ ] User can view their licenses
- [ ] Heartbeat is recorded correctly
- [ ] License is auto-suspended after missed heartbeats
- [ ] Admin can issue/revoke/suspend licenses
- [ ] Vendor can submit plugin (after verification)

---

## Next Steps

1. Review and approve this plan
2. Begin with database migrations
3. Implement models and services
4. Build API endpoints
5. Create frontend views
6. Test and deploy