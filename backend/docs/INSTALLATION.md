# LaravelCP Installation Guide

## Generating Your LICENSE_CALLBACK_SECRET

For secure license activation, you must set a unique LICENSE_CALLBACK_SECRET in your .env file. This secret is used to authenticate license activation callbacks.

### Automatic Method (Recommended)

Run the following Artisan command:
 
 
```bash
php artisan license:generate-secret
```

This will generate a secure random secret and set it in your .env file automatically.

### Manual Method

Alternatively, you can generate a secret manually:

 
```bash
php -r "echo bin2hex(random_bytes(32));"
```

Copy the output and set it in your .env file:


 
```env
LICENSE_CALLBACK_SECRET=your_generated_value
```

**Never share this secret publicly.**

---

Continue with the rest of the installation steps as described below...
