-- Sample SQL file for testing QuickMyImport web interface
-- This file is located in the main directory (same as QuickMyImport.php)

CREATE DATABASE IF NOT EXISTS test_import;
USE test_import;

-- Create a simple users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert some test data
INSERT INTO users (username, email) VALUES
('john_doe', 'john@example.com'),
('jane_smith', 'jane@example.com'),
('bob_wilson', 'bob@example.com'),
('alice_brown', 'alice@example.com'),
('charlie_jones', 'charlie@example.com');

-- Create posts table
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert test posts
INSERT INTO posts (user_id, title, content) VALUES
(1, 'First Post', 'This is my first post content'),
(2, 'Hello World', 'Welcome to my blog'),
(1, 'Another Update', 'More interesting content here'),
(3, 'Technical Article', 'Deep dive into database optimization'),
(4, 'Quick Tips', 'Some useful tips and tricks');

-- Create a view
CREATE OR REPLACE VIEW user_post_count AS
SELECT u.username, u.email, COUNT(p.id) as post_count
FROM users u
LEFT JOIN posts p ON u.id = p.user_id
GROUP BY u.id, u.username, u.email;

-- Show completion message
SELECT 'Sample database created successfully!' as Status;
