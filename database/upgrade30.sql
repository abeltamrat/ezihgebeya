-- Asynchronous source-model conversion for vendor product listings.
-- Original source files are stored under protected_uploads/model-sources and are
-- never exposed directly to browsers.
CREATE TABLE IF NOT EXISTS product_model_conversions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  source_path VARCHAR(500) NOT NULL,
  source_name VARCHAR(255) NOT NULL,
  source_format VARCHAR(20) NOT NULL,
  source_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  provider_job_id VARCHAR(190) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) NULL,
  dispatched_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_model_conversion_queue (status, created_at),
  KEY idx_model_conversion_product (product_id, created_at),
  KEY idx_model_conversion_business (business_id, created_at),
  CONSTRAINT fk_model_conversion_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_model_conversion_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
