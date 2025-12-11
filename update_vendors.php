<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "procureflow";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully or already exists<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// Select database
$conn->select_db($dbname);

// Create users table
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Vendor', 'Guest') DEFAULT 'Vendor',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    verification_token VARCHAR(100),
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql_users) === TRUE) {
    echo "Users table created successfully<br>";
    
    // Check and add missing columns to users table
    $columns_to_check = [
        'status' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'",
        'role' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('Admin', 'Vendor', 'Guest') DEFAULT 'Vendor'",
        'verification_token' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_token VARCHAR(100)",
        'is_verified' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE",
        'name' => "ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(255) NOT NULL"
    ];
    
    foreach ($columns_to_check as $column => $alter_query) {
        // Check if column exists
        $check_column = $conn->query("SHOW COLUMNS FROM users LIKE '$column'");
        if ($check_column->num_rows == 0) {
            if ($conn->query($alter_query) === TRUE) {
                echo "Column '$column' added successfully<br>";
            } else {
                echo "Error adding column '$column': " . $conn->error . "<br>";
            }
        } else {
            echo "Column '$column' already exists<br>";
        }
    }
} else {
    echo "Error creating users table: " . $conn->error . "<br>";
}

// Create vendors table with all required columns
$sql_vendors = "CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    contact VARCHAR(255) DEFAULT '',
    company_name VARCHAR(255),
    address TEXT,
    phone VARCHAR(20),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($sql_vendors) === TRUE) {
    echo "Vendors table created successfully<br>";
    
    // Check and add missing columns to vendors table
    $vendor_columns_to_check = [
        'email' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS email VARCHAR(255) NOT NULL",
        'contact' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS contact VARCHAR(255) DEFAULT ''",
        'company_name' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS company_name VARCHAR(255)",
        'address' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS address TEXT",
        'phone' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS phone VARCHAR(20)",
        'user_id' => "ALTER TABLE vendors ADD COLUMN IF NOT EXISTS user_id INT NOT NULL"
    ];
    
    foreach ($vendor_columns_to_check as $column => $alter_query) {
        // Check if column exists
        $check_column = $conn->query("SHOW COLUMNS FROM vendors LIKE '$column'");
        if ($check_column->num_rows == 0) {
            if ($conn->query($alter_query) === TRUE) {
                echo "Vendor column '$column' added successfully<br>";
            } else {
                echo "Error adding vendor column '$column': " . $conn->error . "<br>";
            }
        } else {
            echo "Vendor column '$column' already exists<br>";
        }
    }
} else {
    echo "Error creating vendors table: " . $conn->error . "<br>";
}

// Create an admin user if not exists
$admin_email = "admin@procureflow.com";
$admin_password = password_hash("admin123", PASSWORD_DEFAULT);
$admin_name = "System Administrator";

$check_admin = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check_admin->bind_param("s", $admin_email);
$check_admin->execute();
$check_admin->store_result();

if ($check_admin->num_rows == 0) {
    $insert_admin = $conn->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'Admin', 'approved')");
    $insert_admin->bind_param("sss", $admin_name, $admin_email, $admin_password);
    
    if ($insert_admin->execute()) {
        echo "Admin user created successfully<br>";
        echo "Admin Email: $admin_email<br>";
        echo "Admin Password: admin123<br>";
    } else {
        echo "Error creating admin user: " . $conn->error . "<br>";
    }
    $insert_admin->close();
} else {
    echo "Admin user already exists<br>";
}

$check_admin->close();

echo "<h3>Database setup completed successfully!</h3>";
echo "<p>You can now <a href='Signup.php'>test the vendor registration</a></p>";
echo "<p>Admin login: admin@procureflow.com / admin123</p>";

$conn->close();
?>