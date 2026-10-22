/* CREATE DATABASE IF NOT EXISTS cl_test;
USE cl_test;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  item_name VARCHAR(100) NOT NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id)
);

INSERT INTO categories (category_name) VALUES
('Dry Ingredients'),
('Dairy'),
('Liquid Ingredients'),
('Fruits'),
('Toppings');

INSERT INTO items (category_id, item_name) VALUES
(1, 'Flour'),
(1, 'Sugar'),
(1, 'Salt'),
(2, 'Milk'),
(2, 'Cheese'),
(3, 'Water'),
(3, 'Oil'),
(4, 'Apple'),
(4, 'Banana'),
(5, 'Chocolate Chips');
*/