<?php
// admin/categories.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Function to handle image upload
function uploadImage($file) {
    $uploadDir = '../uploads/categories/';
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
    $filename = 'category_' . time() . '_' . uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/categories/' . $filename];
    } else {
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }
}

// Function to delete image file
function deleteImage($imagePath) {
    if ($imagePath && file_exists('../' . $imagePath)) {
        unlink('../' . $imagePath);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $image = sanitize($_POST['image_url']); // URL field
        
        // Handle file upload
        if (!empty($_FILES['image_file']['name'])) {
            $uploadResult = uploadImage($_FILES['image_file']);
            if ($uploadResult['success']) {
                $image = $uploadResult['filename'];
            } else {
                showMessage($uploadResult['message'], 'error');
                $image = '';
            }
        }
        
        if (!empty($name)) {
            $query = "INSERT INTO categories (name, description, image) VALUES (?, ?, ?)";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$name, $description, $image])) {
                showMessage('Category added successfully!');
            } else {
                showMessage('Failed to add category', 'error');
            }
        } else {
            showMessage('Category name is required', 'error');
        }
        
    } elseif (isset($_POST['update_category'])) {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $status = sanitize($_POST['status']);
        $image = sanitize($_POST['image_url']); // URL field
        $current_image = sanitize($_POST['current_image']);
        
        // Handle file upload
        if (!empty($_FILES['image_file']['name'])) {
            $uploadResult = uploadImage($_FILES['image_file']);
            if ($uploadResult['success']) {
                // Delete old image if it's a local file
                if ($current_image && strpos($current_image, 'uploads/') === 0) {
                    deleteImage($current_image);
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
                deleteImage($current_image);
            }
        }
        
        $query = "UPDATE categories SET name = ?, description = ?, image = ?, status = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt->execute([$name, $description, $image, $status, $id])) {
            showMessage('Category updated successfully!');
        } else {
            showMessage('Failed to update category', 'error');
        }
        
    } elseif (isset($_POST['delete_category'])) {
        $id = (int)$_POST['id'];
        
        // Get category info to delete image
        $cat_query = "SELECT image FROM categories WHERE id = ?";
        $cat_stmt = $db->prepare($cat_query);
        $cat_stmt->execute([$id]);
        $category = $cat_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if category has menu items
        $check_query = "SELECT COUNT(*) FROM menu_items WHERE category_id = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$id]);
        $item_count = $check_stmt->fetchColumn();
        
        if ($item_count > 0) {
            showMessage('Cannot delete category with existing menu items', 'error');
        } else {
            $query = "DELETE FROM categories WHERE id = ?";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$id])) {
                // Delete associated image file
                if ($category && $category['image'] && strpos($category['image'], 'uploads/') === 0) {
                    deleteImage($category['image']);
                }
                showMessage('Category deleted successfully!');
            } else {
                showMessage('Failed to delete category', 'error');
            }
        }
    }
}

// Get all categories with statistics
$categories_query = "SELECT c.*, COUNT(mi.id) as item_count 
                    FROM categories c 
                    LEFT JOIN menu_items mi ON c.id = mi.category_id 
                    GROUP BY c.id 
                    ORDER BY c.name";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate category statistics
$stats = [];
$stats['total_categories'] = count($categories);
$stats['active_categories'] = count(array_filter($categories, fn($cat) => $cat['status'] === 'active'));
$stats['inactive_categories'] = $stats['total_categories'] - $stats['active_categories'];
$stats['total_items'] = array_sum(array_column($categories, 'item_count'));
$stats['avg_items_per_category'] = $stats['total_categories'] > 0 ? $stats['total_items'] / $stats['total_categories'] : 0;

// Get category for editing
$edit_category = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_query = "SELECT * FROM categories WHERE id = ?";
    $edit_stmt = $db->prepare($edit_query);
    $edit_stmt->execute([$edit_id]);
    $edit_category = $edit_stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management - Admin</title>
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
                    <a href="menu.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                        Menu Management
                    </a>
                    <a href="categories.php" class="nav-item active flex items-center px-4 py-3 text-brand-yellow bg-yellow-50 transition-all duration-300 rounded-lg mx-2 font-medium">
                        <svg class="w-5 h-5 mr-3 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-600 rounded-2xl flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-gray-800">Category Management</h1>
                                <p class="text-gray-600 mt-1">Organize and manage your menu categories</p>
                            </div>
                        </div>
                        <button onclick="showAddForm()" class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-8 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Add New Category
                        </button>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Categories Card -->
                    <div class="gradient-card from-blue-500 to-blue-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Categories</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_categories'] ?></div>
                        <div class="text-white/70 text-sm">All menu categories</div>
                    </div>

                    <!-- Active Categories Card -->
                    <div class="gradient-card from-green-500 to-green-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Active Categories</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['active_categories'] ?></div>
                        <div class="text-white/70 text-sm">Currently available</div>
                    </div>

                    <!-- Total Menu Items Card -->
                    <div class="gradient-card from-purple-500 to-purple-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Menu Items</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_items'] ?></div>
                        <div class="text-white/70 text-sm">Across all categories</div>
                    </div>

                    <!-- Average Items Per Category Card -->
                    <div class="gradient-card from-brand-yellow to-brand-amber rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Avg Items/Category</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= number_format($stats['avg_items_per_category'], 1) ?></div>
                        <div class="text-white/70 text-sm">Items per category</div>
                    </div>
                </div>

                <!-- Add/Edit Form -->
                <div id="categoryForm" class="<?= $edit_category ? '' : 'hidden' ?> mb-8">
                    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                    </svg>
                                    <div>
                                        <h3 class="text-white font-semibold text-lg">
                                            <?= $edit_category ? 'Edit Category' : 'Add New Category' ?>
                                        </h3>
                                        <p class="text-white/80 text-sm">
                                            <?= $edit_category ? 'Update category information and settings' : 'Create a new menu category' ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" class="p-6">
                            <?php if ($edit_category): ?>
                                <input type="hidden" name="id" value="<?= $edit_category['id'] ?>">
                                <input type="hidden" name="current_image" value="<?= htmlspecialchars($edit_category['image'] ?? '') ?>">
                            <?php endif; ?>
                            
                            <div class="grid md:grid-cols-2 gap-6 mb-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Category Name *</label>
                                    <input type="text" name="name" required 
                                           value="<?= htmlspecialchars($edit_category['name'] ?? '') ?>"
                                           class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent"
                                           placeholder="Enter category name">
                                </div>
                                
                                <?php if ($edit_category): ?>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                                    <select name="status" 
                                            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent">
                                        <option value="active" <?= ($edit_category['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= ($edit_category['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" rows="4" 
                                          class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent"
                                          placeholder="Describe this category..."><?= htmlspecialchars($edit_category['description'] ?? '') ?></textarea>
                            </div>
                            
                            <!-- Current Image Preview -->
                            <?php if ($edit_category && $edit_category['image']): ?>
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Current Image</label>
                                <div class="relative inline-block">
                                    <img src="../<?= htmlspecialchars($edit_category['image']) ?>" alt="Current category image" 
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
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Upload New Image</label>
                                <div class="mt-1 flex justify-center px-6 pt-8 pb-8 border-2 border-brand-yellow border-dashed rounded-xl bg-yellow-50 hover:bg-yellow-100 transition-all duration-300">
                                    <div class="space-y-2 text-center">
                                        <svg class="mx-auto h-16 w-16 text-brand-yellow" stroke="currentColor" fill="none" viewBox="0 0 48 48">
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
                                    </div>
                                </div>
                                
                                <!-- Image Preview -->
                                <div id="imagePreview" class="mt-4 hidden">
                                    <div class="relative inline-block">
                                        <img id="previewImg" src="" alt="Preview" class="w-32 h-32 object-cover rounded-xl border-4 border-brand-yellow shadow-lg">
                                        <div class="absolute -top-2 -right-2 w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M19 7L18.3 7.7L16.6 6L17.3 5.3C17.7 4.9 18.3 4.9 18.7 5.3L19 5.6C19.4 6 19.4 6.6 19 7M15.9 6.7L9 13.6V16H11.4L18.3 9.1L15.9 6.7Z"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- OR Image URL -->
                            <div class="mb-8">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">OR Image URL</label>
                                <input type="url" name="image_url" 
                                       value="<?= htmlspecialchars($edit_category['image'] ?? '') ?>"
                                       class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-yellow focus:border-transparent"
                                       placeholder="https://example.com/image.jpg">
                                <p class="mt-2 text-sm text-gray-500">Note: Uploading a file will override the URL</p>
                            </div>
                            
                            <div class="flex space-x-4 pt-6 border-t border-gray-200">
                                <button type="submit" name="<?= $edit_category ? 'update_category' : 'add_category' ?>" 
                                        class="flex-1 bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-8 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <?= $edit_category ? 'Update Category' : 'Create Category' ?>
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

                <!-- Categories Grid -->
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                <h3 class="text-white font-semibold text-lg">Menu Categories</h3>
                            </div>
                            <div class="text-white/90 text-sm">
                                <span class="bg-white/20 px-3 py-1 rounded-full">
                                    <?= count($categories) ?> Total Categories
                                </span>
                            </div>
                        </div>
                        <p class="text-white/80 text-sm mt-1">Manage your restaurant's menu categories (<?= count($categories) ?> categories)</p>
                    </div>

                    <div class="p-8">
                        <?php if (empty($categories)): ?>
                        <div class="text-center py-16">
                            <div class="w-24 h-24 mx-auto mb-6 bg-gray-100 rounded-full flex items-center justify-center">
                                <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-700 mb-2">No Categories Yet</h3>
                            <p class="text-gray-500 mb-4">Start organizing your menu by creating your first category.</p>
                            <button onclick="showAddForm()" class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-6 py-3 rounded-lg font-semibold hover:shadow-lg transition-all duration-300">
                                Create First Category
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($categories as $category): ?>
                                <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-200 hover:shadow-xl hover-lift">
                                    <?php 
                                    $imageSrc = $category['image'] ? '../' . $category['image'] : 'https://via.placeholder.com/300x200/FCD34D/FFFFFF?text=No+Image';
                                    ?>
                                    <div class="relative">
                                        <img src="<?= $imageSrc ?>" 
                                             alt="<?= htmlspecialchars($category['name']) ?>" 
                                             class="w-full h-48 object-cover"
                                             onerror="this.src='https://via.placeholder.com/300x200/FCD34D/FFFFFF?text=No+Image'">
                                        
                                        <!-- Status Badge -->
                                        <div class="absolute top-4 right-4">
                                            <span class="px-3 py-1 text-xs font-bold rounded-full shadow-lg
                                                <?= $category['status'] === 'active' ? 'bg-green-500 text-white' : 'bg-red-500 text-white' ?>">
                                                <?= $category['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="p-6">
                                        <div class="flex items-center justify-between mb-3">
                                            <h3 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($category['name']) ?></h3>
                                            <div class="flex items-center space-x-1 bg-blue-50 px-3 py-1 rounded-full">
                                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                                </svg>
                                                <span class="text-blue-800 text-sm font-semibold"><?= $category['item_count'] ?> items</span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($category['description']): ?>
                                        <p class="text-gray-600 text-sm mb-4 line-clamp-2"><?= htmlspecialchars($category['description']) ?></p>
                                        <?php endif; ?>
                                        
                                        <div class="flex space-x-3">
                                            <a href="categories.php?edit=<?= $category['id'] ?>" 
                                               class="flex-1 bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-4 py-2 rounded-lg font-semibold hover:shadow-lg transition-all duration-300 text-center inline-flex items-center justify-center">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                                Edit
                                            </a>
                                            
                                            <?php if ($category['item_count'] == 0): ?>
                                                <form method="POST" class="flex-1" onsubmit="return confirmDelete('<?= htmlspecialchars($category['name']) ?>')">
                                                    <input type="hidden" name="id" value="<?= $category['id'] ?>">
                                                    <button type="submit" name="delete_category" 
                                                            class="w-full bg-red-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-red-600 transition-all duration-300 inline-flex items-center justify-center">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <div class="flex-1 bg-gray-100 text-gray-500 px-4 py-2 rounded-lg font-semibold text-center cursor-not-allowed">
                                                    Cannot Delete
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function showAddForm() {
            document.getElementById('categoryForm').classList.remove('hidden');
            document.querySelector('input[name="name"]').focus();
        }
        
        function hideForm() {
            document.getElementById('categoryForm').classList.add('hidden');
            window.location.href = 'categories.php';
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
        
        function confirmDelete(categoryName) {
            return confirm(`Are you sure you want to delete the category "${categoryName}"? This action cannot be undone.`);
        }
        
        // Drag and drop functionality
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
                dropArea.classList.add('border-brand-yellow', 'bg-yellow-100');
            }
            
            function unhighlight(e) {
                dropArea.classList.remove('border-brand-yellow', 'bg-yellow-100');
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
                    const form = document.getElementById('categoryForm');
                    if (form && !form.classList.contains('hidden')) {
                        hideForm();
                    }
                }
            });
        });
    </script>
</body>
</html>