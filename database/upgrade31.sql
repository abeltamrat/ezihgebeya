-- Software and plugin library for vendor tenants.

CREATE TABLE IF NOT EXISTS software_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(210) NOT NULL UNIQUE,
  item_type ENUM('software','plugin') NOT NULL DEFAULT 'software',
  short_description VARCHAR(300) NOT NULL,
  description TEXT NOT NULL,
  version VARCHAR(80) NULL,
  developer VARCHAR(150) NULL,
  category VARCHAR(100) NULL,
  platforms VARCHAR(255) NULL,
  license_type VARCHAR(100) NULL,
  file_path VARCHAR(255) NULL,
  original_filename VARCHAR(255) NULL,
  file_size BIGINT UNSIGNED NULL,
  external_url VARCHAR(1000) NULL,
  youtube_url VARCHAR(1000) NULL,
  youtube_video_id VARCHAR(24) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_software_library (status, is_featured, published_at),
  KEY idx_software_type (item_type, category),
  CONSTRAINT fk_software_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS software_screenshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  software_id BIGINT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  caption VARCHAR(180) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_software_screenshot_order (software_id, sort_order, id),
  CONSTRAINT fk_software_screenshot_item FOREIGN KEY (software_id) REFERENCES software_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS software_downloads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  software_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  delivery_type ENUM('file','external') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_software_download_item (software_id, created_at),
  KEY idx_software_download_user (user_id, created_at),
  CONSTRAINT fk_software_download_item FOREIGN KEY (software_id) REFERENCES software_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_software_download_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
