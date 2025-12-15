CREATE DATABASE IF NOT EXISTS techwyse_shopify_ptl_db;
CREATE USER IF NOT EXISTS 'techwyse_shopify_ptl_user'@'localhost' IDENTIFIED BY '^Y!1iOEn?O3p';
GRANT ALL PRIVILEGES ON techwyse_shopify_ptl_db.* TO 'techwyse_shopify_ptl_user'@'localhost';
FLUSH PRIVILEGES;
