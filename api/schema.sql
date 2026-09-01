CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  role ENUM('owner','admin','editor') NOT NULL,
  status ENUM('invited','active') NOT NULL DEFAULT 'invited',
  google_sub VARCHAR(255) NULL,
  invited_by INT NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id VARCHAR(32) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  client VARCHAR(255) NOT NULL,
  editor_id INT NOT NULL,
  assigned_by INT NOT NULL,
  date_assigned DATETIME NOT NULL,
  due_at DATETIME NOT NULL,
  priority ENUM('Urgent','High','Medium','Low') NOT NULL,
  stage VARCHAR(64) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  platform VARCHAR(255) NOT NULL,
  aspect VARCHAR(32) NOT NULL,
  delivery_link VARCHAR(1024) NULL,
  instructions TEXT NULL,
  delivered_at DATETIME NULL,
  delivered_on_time TINYINT(1) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (editor_id) REFERENCES users(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deliverables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  label VARCHAR(255) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  kind ENUM('asset','reference') NOT NULL,
  label VARCHAR(255) NOT NULL,
  url VARCHAR(1024) NOT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS revisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  note TEXT NOT NULL,
  author VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
