# QODA Deployment

QODA is a PHP/MySQL exam system. The full app needs:

- PHP with PDO MySQL enabled
- MySQL or MariaDB
- Writable folders for runtime files, uploads, and sessions
- A server that can execute PHP scripts

## GitHub

GitHub should be used for source control. Do not commit `.env`, uploads, runtime compiler files, or `node_modules`.

After creating an empty GitHub repository, push with:

```bash
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
git push -u origin main
```

## Environment Variables

Production hosts should set:

```bash
DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=your-database-name
DB_USER=your-database-user
DB_PASS=your-database-password
```

Import `backend-php/database.sql` into the production database.

## Hosting Notes

GitHub Pages, Netlify, and standard Vercel deployments are static/serverless-first platforms. They do not run this PHP/MySQL/XAMPP-style application as-is.

Use one of these instead for the complete system:

- A PHP shared host with MySQL and cPanel
- A VPS with Apache/Nginx, PHP, and MySQL/MariaDB
- A Docker/PHP-capable platform with a managed MySQL database

Netlify or Vercel can host only a static marketing page for QODA unless the app is rebuilt into a supported serverless architecture.
