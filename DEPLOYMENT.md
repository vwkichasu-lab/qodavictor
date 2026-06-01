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

## Docker Deployment

This repo includes a `Dockerfile` for PHP/Apache. The image installs PHP MySQL extensions and the local code execution tools used by QODA for Python, JavaScript, Java, C, C++, and PHP questions.

Any Docker-capable host should set the database environment variables above and import `backend-php/database.sql`.

Railway and Render configuration files are included:

- `railway.json`
- `render.yaml`

For Railway, create a project from the GitHub repo, add a MySQL service, and set:

```bash
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_NAME=${{MySQL.MYSQLDATABASE}}
DB_USER=${{MySQL.MYSQLUSER}}
DB_PASS=${{MySQL.MYSQLPASSWORD}}
```

For Render, use an external MySQL database provider and set the same variables in the service environment.

## Hosting Notes

GitHub Pages, Netlify, and standard Vercel deployments are static/serverless-first platforms. They do not run this PHP/MySQL/XAMPP-style application as-is.

Use one of these instead for the complete system:

- A PHP shared host with MySQL and cPanel
- A VPS with Apache/Nginx, PHP, and MySQL/MariaDB
- A Docker/PHP-capable platform with a managed MySQL database

Netlify or Vercel can host only a static marketing page for QODA unless the app is rebuilt into a supported serverless architecture.
