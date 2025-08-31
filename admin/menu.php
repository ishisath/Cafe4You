<?php
// admin/menu.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Function to handle image upload
function uploadMenuImage($file) {
    $uploadDir = '../uploads/menu/';
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Create upload directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Check if file was uploaded
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error occurred'];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size too large (max 5MB)'];
    }
    
    // Get file extension
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    // Check file type
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, JPEG, PNG, GIF, WEBP allowed'];
    }
    
    // Generate unique filename
    $filename = 'menu_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/menu/' . $filename];
    } else {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }
}

// Function to delete image file
function deleteMenuImage($imagePath) {
    if ($imagePath && file_exists('../' . $imagePath)) {
        unlink('../' . $imagePath);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $price = (float)$_POST['price'];
        $category_id = (int)$_POST['category_id'];
        $image = sanitize($_POST['image_url']); // URL field
        
        // Handle file upload
        if (!empty($_FILES['image_file']['name'])) {
            $uploadResult = uploadMenuImage($_FILES['image_file']);
            if ($uploadResult['success']) {
                $image = $uploadResult['filename'];
            } else {
                showMessage($uploadResult['message'], 'error');
                $image = '';
            }
        }
        
        if (!empty($name) && !empty($category_id) && $price > 0) {
            $query = "INSERT INTO menu_items (name, description, price, category_id, image) VALUES (?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$name, $description, $price, $category_id, $image])) {
                showMessage('Menu item added successfully!');
            } else {
                showMessage('Failed to add menu item', 'error');
            }
        } else {
            showMessage('Please fill in all required fields', 'error');
        }
        
    } elseif (isset($_POST['update_item'])) {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $price = (float)$_POST['price'];
        $category_id = (int)$_POST['category_id'];
        $status = sanitize($_POST['status']);
        $image = sanitize($_POST['image_url']); // URL field
        $current_image = sanitize($_POST['current_image']);
        
        // Handle file upload
        if (!empty($_FILES['image_file']['name'])) {
            $uploadResult = uploadMenuImage($_FILES['image_file']);
            if ($uploadResult['success']) {
                // Delete old image if it's a local file
                if ($current_image && strpos($current_image, 'uploads/') === 0) {
                    deleteMenuImage($current_image);
                }
                $image = $uploadResult['filename'];
            } else {
                showMessage($uploadResult['message'], 'error');
                $image = $current_image; // Keep current image if upload fails
            }
        } elseif (empty($image)) {
            $image = $current_image; // Keep current image if no new image provided
        } elseif ($image !== $current_image) {
            // New URL provided, delete old local file if exists
            if ($current_image && strpos($current_image, 'uploads/') === 0) {
                deleteMenuImage($current_image);
            }
        }
        
        $query = "UPDATE menu_items SET name = ?, description = ?, price = ?, category_id = ?, image = ?, status = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt->execute([$name, $description, $price, $category_id, $image, $status, $id])) {
            showMessage('Menu item updated successfully!');
        } else {
            showMessage('Failed to update menu item', 'error');
        }
        
    } elseif (isset($_POST['delete_item'])) {
        $id = (int)$_POST['id'];
        
        // Get item info to delete image
        $item_query = "SELECT image FROM menu_items WHERE id = ?";
        $item_stmt = $db->prepare($item_query);
        $item_stmt->execute([$id]);
        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
        
        $query = "DELETE FROM menu_items WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt->execute([$id])) {
            // Delete associated image file
            if ($item && $item['image'] && strpos($item['image'], 'uploads/') === 0) {
                deleteMenuImage($item['image']);
            }
            showMessage('Menu item deleted successfully!');
        } else {
            showMessage('Failed to delete menu item', 'error');
        }
    }
}

// Get categories for dropdown
$cat_query = "SELECT * FROM categories WHERE status = 'active' ORDER BY name";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all menu items with statistics
$menu_query = "SELECT mi.*, c.name as category_name FROM menu_items mi 
               LEFT JOIN categories c ON mi.category_id = c.id 
               ORDER BY c.name, mi.name";
$menu_stmt = $db->prepare($menu_query);
$menu_stmt->execute();
$menu_items = $menu_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get menu statistics
$stats = [];
$stats['total_items'] = count($menu_items);
$stats['available_items'] = count(array_filter($menu_items, function($item) { return $item['status'] === 'available'; }));
$stats['unavailable_items'] = $stats['total_items'] - $stats['available_items'];

// Average price
$total_price = array_sum(array_column($menu_items, 'price'));
$stats['avg_price'] = $stats['total_items'] > 0 ? $total_price / $stats['total_items'] : 0;

// Get item for editing
$edit_item = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_query = "SELECT * FROM menu_items WHERE id = ?";
    $edit_stmt = $db->prepare($edit_query);
    $edit_stmt->execute([$edit_id]);
    $edit_item = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'brand-yellow': '#FCD34D',
                        'brand-amber': '#F59E0B',
                        'brand-cream': '#FFF8F0',
                        'sidebar-bg': '#2C3E50',
                        'sidebar-hover': '#34495E'
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        
        .card-shadow {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .gradient-card {
            background: linear-gradient(135deg, var(--tw-gradient-stops));
        }
        
        .nav-item {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #FCD34D;
            border-radius: 0 4px 4px 0;
        }
        
        .nav-item.active {
            background: rgba(252, 211, 77, 0.1);
            color: #FCD34D;
            border-right: 3px solid #FCD34D;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <!-- Top Navigation -->
    <nav class="bg-sidebar-bg text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <div class="w-10 h-10 bg-gradient-to-br from-brand-yellow to-brand-amber rounded-xl flex items-center justify-center">
                        <span class="text-white font-bold text-lg">C</span>
                    </div>
                    <h1 class="text-xl font-bold">Cafe For You - Admin</h1>
                </div>

                <div class="flex items-center space-x-6">
                    <span class="text-gray-300">Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></span>
                    <a href="../index.php" class="text-gray-300 hover:text-white transition-colors duration-300">View Site</a>
                    <a href="../logout.php" class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-4 py-2 rounded-lg hover:shadow-lg transform hover:scale-105 transition-all duration-300 font-medium">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-white shadow-lg">
            <nav class="mt-8">
                <div class="px-4 space-y-2">
                    <a href="dashboard.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                        </svg>
                        Dashboard
                    </a>
                    <a href="orders.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                        </svg>
                        Orders
                    </a>
                    <a href="menu.php" class="nav-item active flex items-center px-4 py-3 text-brand-yellow bg-yellow-50 transition-all duration-300 rounded-lg mx-2 font-medium">
                        <svg class="w-5 h-5 mr-3 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                        Menu Management
                    </a>
                    <a href="categories.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        Categories
                    </a>
                    <a href="reservations.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                        </svg>
                        Reservations
                    </a>
                    <a href="users.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                        Users
                    </a>
                    <a href="messages.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        Contact Messages
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-hidden">
            <div class="p-8">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gradient-to-br from-indigo-400 to-indigo-600 rounded-2xl flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-gray-800">Menu Management</h1>
                                <p class="text-gray-600 mt-1">Create and manage delicious menu items for your restaurant</p>
                            </div>
                        </div>
                        <button onclick="showAddForm()" class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-8 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add New Item
                        </button>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Items Card -->
                    <div class="gradient-card from-blue-500 to-blue-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Items</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_items'] ?></div>
                        <div class="text-white/70 text-sm">All menu items</div>
                    </div>

                    <!-- Available Items Card -->
                    <div class="gradient-card from-green-500 to-green-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Available Items</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['available_items'] ?></div>
                        <div class="text-white/70 text-sm">Ready to order</div>
                    </div>

                    <!-- Unavailable Items Card -->
                    <div class="gradient-card from-red-500 to-red-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Unavailable</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['unavailable_items'] ?></div>
                        <div class="text-white/70 text-sm">Currently unavailable</div>
                    </div>

                    <!-- Average Price Card -->
                    <div class="gradient-card from-brand-yellow to-brand-amber rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Average Price</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2">$<?= number_format($stats['avg_price'], 2) ?></div>
                        <div class="text-white/70 text-sm">Per menu item</div>
                    </div>
                </div>

                <!-- Add/Edit Form -->
                <div id="itemForm" class="<?= $edit_item ? '' : 'hidden' ?> mb-8">
                    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                    </svg>
                                    <div>
                                        <h3 class="text-white font-semibold text-lg">
                                            <?= $edit_item ? 'Edit Menu Item' : 'Add New Menu Item' ?>
                                        </h3>
                                        <p class="text-white/80 text-sm">
                                            <?= $edit_item ? 'Update menu item information and settings' : 'Create a new delicious menu item' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" class="p-6">
                            <?php if ($edit_item): ?>
                                <input type="hidden" name="id" value="<?= $edit_item['id'] ?>">
                                <input type="hidden" name="current_image" value="<?= htmlspecialchars($edit_item['image'] ?? '') ?>">
                            <?php endif; ?>
                            
                            <div class="grid md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Item Name *</label>
                                    <input type="text" name="name" required 
                                           value="<?= htmlspecialchars($edit_item['name'] ?? '') ?>"
                                           class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent"
                                           placeholder="Enter delicious dish name...">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Category *</label>
                                    <select name="category_id" required 
                                            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category['id'] ?>" 
                                                    <?= ($edit_item && $edit_item['category_id'] == $category['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($category['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="grid md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Price *</label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 font-bold text-lg">$</span>
                                        <input type="number" name="price" step="0.01" min="0" required 
                                               value="<?= $edit_item['price'] ?? '' ?>"
                                               class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent">
                                    </div>
                                </div>
                                
                                <?php if ($edit_item): ?>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                                    <select name="status" 
                                            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent">
                                        <option value="available" <?= ($edit_item['status'] === 'available') ? 'selected' : '' ?>>Available</option>
                                        <option value="unavailable" <?= ($edit_item['status'] === 'unavailable') ? 'selected' : '' ?>>Unavailable</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" rows="4" 
                                          class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent resize-none"
                                          placeholder="Describe your delicious menu item in detail..."><?= htmlspecialchars($edit_item['description'] ?? '') ?></textarea>
                            </div>
                            
                            <!-- Current Image Preview -->
                            <?php if ($edit_item && $edit_item['image']): ?>
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Current Image</label>
                                <div class="relative inline-block">
                                    <img src="../<?= htmlspecialchars($edit_item['image']) ?>" alt="Current menu item image" 
                                         class="w-32 h-32 object-cover rounded-xl border-4 border-gray-200 shadow-lg">
                                    <div class="absolute -top-2 -right-2 w-6 h-6 bg-green-500 rounded-full flex items-center justify-center">
                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M9 12L11 14L15 10M21 12C21 16.97 16.97 21 12 21C7.03 21 3 16.97 3 12C3 7.03 7.03 3 12 3C16.97 3 21 7.03 21 12Z"/>
                                        </svg>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Image Upload -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Upload Food Image</label>
                                <div class="mt-1 flex justify-center px-6 pt-8 pb-8 border-2 border-brand-yellow border-dashed rounded-xl bg-yellow-50 hover:bg-yellow-100 transition-all duration-300">
                                    <div class="space-y-2 text-center">
                                        <svg class="mx-auto h-16 w-16 text-brand-yellow" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        <div class="text-brand-yellow">
                                            <label for="image_file" class="relative cursor-pointer bg-transparent rounded-md font-semibold text-brand-yellow hover:text-yellow-700 focus-within:outline-none transition-colors duration-300">
                                                <span class="text-lg">Upload a file</span>
                                                <input id="image_file" name="image_file" type="file" class="sr-only" accept="image/*" onchange="previewImage(this)">
                                            </label>
                                            <p class="text-brand-yellow">or drag and drop</p>
                                        </div>
                                        <p class="text-sm text-gray-500">PNG, JPG, GIF, WEBP up to 5MB</p>
                                        <p class="text-xs text-brand-amber font-medium">High-quality food photos work best!</p>
                                    </div>
                                </div>
                                
                                <!-- Image Preview -->
                                <div id="imagePreview" class="mt-4 hidden">
                                    <div class="flex items-center space-x-6 p-4 bg-green-50 rounded-xl border border-green-200">
                                        <img id="previewImg" src="" alt="Preview" class="w-24 h-24 object-cover rounded-xl border-2 border-green-300">
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-green-800">Image Preview</p>
                                            <p class="text-xs text-green-600 mt-1">This is how your food photo will appear</p>
                                            <button type="button" onclick="removePreview()" class="mt-3 text-xs text-red-600 hover:text-red-800 font-medium bg-red-100 px-3 py-1 rounded-full transition-colors">
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- OR Image URL -->
                            <div class="mb-8">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">OR Image URL</label>
                                <input type="url" name="image_url" 
                                       value="<?= htmlspecialchars($edit_item['image'] ?? '') ?>"
                                       class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent"
                                       placeholder="https://example.com/delicious-food-image.jpg">
                                <p class="mt-2 text-xs text-gray-500">Note: Uploading a file will override the URL</p>
                            </div>
                            
                            <div class="flex space-x-4 pt-6 border-t border-gray-200">
                                <button type="submit" name="<?= $edit_item ? 'update_item' : 'add_item' ?>" 
                                        class="flex-1 bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-8 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <?= $edit_item ? 'Update Menu Item' : 'Add Menu Item' ?>
                                </button>
                                <button type="button" onclick="hideForm()" 
                                        class="flex-1 bg-gray-200 text-gray-700 px-8 py-3 rounded-xl font-semibold hover:bg-gray-300 transition-all duration-300">
                                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Menu Items Table -->
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                                <h3 class="text-white font-semibold text-lg">Menu Items</h3>
                            </div>
                            <div class="text-white/90 text-sm">
                                <span class="bg-white/20 px-3 py-1 rounded-full">
                                    <?= count($menu_items) ?> Total Items
                                </span>
                            </div>
                        </div>
                        <p class="text-white/80 text-sm mt-1">Manage your delicious offerings (<?= count($menu_items) ?> items)</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">FOOD ITEM</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">CATEGORY</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">PRICE</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">STATUS</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php foreach ($menu_items as $item): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <?php 
                                                $imageSrc = $item['image'] ? '../' . $item['image'] : 'https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?w=80&h=80&fit=crop&crop=center';
                                                ?>
                                                <div class="relative">
                                                    <img class="h-16 w-16 rounded-xl object-cover border-2 border-brand-yellow shadow-md" 
                                                         src="<?= $imageSrc ?>" 
                                                         alt="<?= htmlspecialchars($item['name']) ?>"
                                                         onerror="this.src='https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?w=80&h=80&fit=crop&crop=center'">
                                                    <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-brand-amber rounded-full flex items-center justify-center">
                                                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M12 2L15.09 8.26L22 9L17 14L18.18 21L12 17.77L5.82 21L7 14L2 9L8.91 8.26L12 2Z"/>
                                                        </svg>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($item['name']) ?></div>
                                                    <div class="text-xs text-gray-500 max-w-xs truncate"><?= htmlspecialchars($item['description']) ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?= htmlspecialchars($item['category_name'] ?? 'No Category') ?>
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-bold text-gray-900">$<?= number_format($item['price'], 2) ?></div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                                                <?= $item['status'] === 'available' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $item['status'] === 'available' ? 'Available' : 'Unavailable' ?>
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex space-x-2">
                                                <a href="menu.php?edit=<?= $item['id'] ?>" 
                                                   class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                                                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                    Edit
                                                </a>
                                                <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this menu item?')">
                                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                    <button type="submit" name="delete_item" 
                                                            class="bg-red-500 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-red-600 transition-all duration-300">
                                                        <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($menu_items)): ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">No Menu Items Yet</h3>
                        <p class="text-gray-500 mb-4">Start building your delicious menu by adding your first item.</p>
                        <button onclick="showAddForm()" class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300">
                            Add Your First Menu Item
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function showAddForm() {
            document.getElementById('itemForm').classList.remove('hidden');
            document.getElementById('itemForm').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideForm() {
            document.getElementById('itemForm').classList.add('hidden');
            window.location.href = 'menu.php';
        }
        
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').classList.remove('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function removePreview() {
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('image_file').value = '';
        }
        
        // Enhanced drag and drop functionality
        const dropArea = document.querySelector('.border-dashed');
        const fileInput = document.getElementById('image_file');
        
        if (dropArea && fileInput) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight(e) {
                dropArea.classList.add('border-yellow-600', 'bg-yellow-100');
                dropArea.classList.remove('border-brand-yellow', 'bg-yellow-50');
            }
            
            function unhighlight(e) {
                dropArea.classList.remove('border-yellow-600', 'bg-yellow-100');
                dropArea.classList.add('border-brand-yellow', 'bg-yellow-50');
            }
            
            dropArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;
                previewImage(fileInput);
            }
        }
        
        // Enhanced modal handling and interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Close form on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const form = document.getElementById('itemForm');
                    if (form && !form.classList.contains('hidden')) {
                        hideForm();
                    }
                }
            });
        });
    </script>
</body>
</html>