<?php
// admin/dashboard.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Get comprehensive statistics
$stats = [];

// Total orders
$orders_query = "SELECT COUNT(*) as total, SUM(total_amount) as revenue FROM orders";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->execute();
$orders_data = $orders_stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_orders'] = $orders_data['total'];
$stats['total_revenue'] = $orders_data['revenue'] ?? 0;

// Today's orders and revenue
$today_orders_query = "SELECT COUNT(*) as today_orders, SUM(total_amount) as today_revenue 
                       FROM orders WHERE DATE(created_at) = CURDATE()";
$today_orders_stmt = $db->prepare($today_orders_query);
$today_orders_stmt->execute();
$today_data = $today_orders_stmt->fetch(PDO::FETCH_ASSOC);
$stats['today_orders'] = $today_data['today_orders'] ?? 0;
$stats['today_revenue'] = $today_data['today_revenue'] ?? 0;

// This month's revenue
$month_revenue_query = "SELECT SUM(total_amount) as month_revenue 
                        FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) 
                        AND YEAR(created_at) = YEAR(CURDATE())";
$month_revenue_stmt = $db->prepare($month_revenue_query);
$month_revenue_stmt->execute();
$stats['month_revenue'] = $month_revenue_stmt->fetchColumn() ?? 0;

// Average order value
$stats['avg_order_value'] = $stats['total_orders'] > 0 ? $stats['total_revenue'] / $stats['total_orders'] : 0;

// Total customers
$customers_query = "SELECT COUNT(*) as total FROM users WHERE role = 'customer'";
$customers_stmt = $db->prepare($customers_query);
$customers_stmt->execute();
$stats['total_customers'] = $customers_stmt->fetchColumn();

// New customers this month
$new_customers_query = "SELECT COUNT(*) as new_customers FROM users 
                        WHERE role = 'customer' AND MONTH(created_at) = MONTH(CURDATE()) 
                        AND YEAR(created_at) = YEAR(CURDATE())";
$new_customers_stmt = $db->prepare($new_customers_query);
$new_customers_stmt->execute();
$stats['new_customers'] = $new_customers_stmt->fetchColumn();

// Total menu items
$menu_query = "SELECT COUNT(*) as total FROM menu_items";
$menu_stmt = $db->prepare($menu_query);
$menu_stmt->execute();
$stats['total_menu_items'] = $menu_stmt->fetchColumn();

// Available menu items
$available_menu_query = "SELECT COUNT(*) as available FROM menu_items WHERE status = 'available'";
$available_menu_stmt = $db->prepare($available_menu_query);
$available_menu_stmt->execute();
$stats['available_menu_items'] = $available_menu_stmt->fetchColumn();

// Total reservations
$reservations_query = "SELECT COUNT(*) as total FROM reservations";
$reservations_stmt = $db->prepare($reservations_query);
$reservations_stmt->execute();
$stats['total_reservations'] = $reservations_stmt->fetchColumn();

// Today's reservations
$today_reservations_query = "SELECT COUNT(*) as today_reservations FROM reservations WHERE date = CURDATE()";
$today_reservations_stmt = $db->prepare($today_reservations_query);
$today_reservations_stmt->execute();
$stats['today_reservations'] = $today_reservations_stmt->fetchColumn();

// Pending reservations
$pending_reservations_query = "SELECT COUNT(*) as pending FROM reservations WHERE status = 'pending'";
$pending_reservations_stmt = $db->prepare($pending_reservations_query);
$pending_reservations_stmt->execute();
$stats['pending_reservations'] = $pending_reservations_stmt->fetchColumn();

// Order status breakdown
$order_status_query = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
$order_status_stmt = $db->prepare($order_status_query);
$order_status_stmt->execute();
$order_status_data = $order_status_stmt->fetchAll(PDO::FETCH_ASSOC);
$stats['order_status'] = [];
foreach ($order_status_data as $status) {
    $stats['order_status'][$status['status']] = $status['count'];
}

// Most popular menu items
$popular_items_query = "SELECT mi.name, SUM(oi.quantity) as total_ordered 
                        FROM order_items oi 
                        JOIN menu_items mi ON oi.menu_item_id = mi.id 
                        GROUP BY mi.id, mi.name 
                        ORDER BY total_ordered DESC 
                        LIMIT 5";
$popular_items_stmt = $db->prepare($popular_items_query);
$popular_items_stmt->execute();
$popular_items = $popular_items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent orders
$recent_orders_query = "SELECT o.id, o.total_amount, o.status, o.created_at, u.full_name, u.email 
                       FROM orders o 
                       JOIN users u ON o.user_id = u.id 
                       ORDER BY o.created_at DESC 
                       LIMIT 5";
$recent_orders_stmt = $db->prepare($recent_orders_query);
$recent_orders_stmt->execute();
$recent_orders = $recent_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent reservations
$recent_reservations_query = "SELECT * FROM reservations ORDER BY created_at DESC LIMIT 5";
$recent_reservations_stmt = $db->prepare($recent_reservations_query);
$recent_reservations_stmt->execute();
$recent_reservations = $recent_reservations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending contact messages
$messages_query = "SELECT COUNT(*) as total FROM contact_messages WHERE status = 'unread'";
$messages_stmt = $db->prepare($messages_query);
$messages_stmt->execute();
$stats['unread_messages'] = $messages_stmt->fetchColumn();

// Monthly revenue chart data (last 6 months)
$monthly_revenue_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    SUM(total_amount) as revenue,
    COUNT(*) as orders
    FROM orders 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6";
$monthly_revenue_stmt = $db->prepare($monthly_revenue_query);
$monthly_revenue_stmt->execute();
$monthly_data = $monthly_revenue_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Cafe For You</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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
                    <a href="dashboard.php" class="nav-item active flex items-center px-4 py-3 text-brand-yellow bg-yellow-50 transition-all duration-300 rounded-lg mx-2 font-medium">
                        <svg class="w-5 h-5 mr-3 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                        <?php if ($stats['unread_messages'] > 0): ?>
                            <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full ml-2">
                                <?= $stats['unread_messages'] ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-hidden">
            <div class="p-8">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-400 to-blue-600 rounded-2xl flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-800">Dashboard Overview</h1>
                            <p class="text-gray-600 mt-1">Welcome to your restaurant management system - Real-time insights and analytics</p>
                        </div>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <!-- Key Performance Indicators -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Revenue Card -->
                    <div class="gradient-card from-green-500 to-green-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Revenue</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2">$<?= number_format($stats['total_revenue'], 2) ?></div>
                        <div class="text-white/70 text-sm">All time</div>
                    </div>

                    <!-- Total Orders Card -->
                    <div class="gradient-card from-blue-500 to-blue-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Orders</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= number_format($stats['total_orders']) ?></div>
                        <div class="text-white/70 text-sm">Average: $<?= number_format($stats['avg_order_value'], 2) ?></div>
                    </div>

                    <!-- Total Customers Card -->
                    <div class="gradient-card from-purple-500 to-purple-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Customers</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= number_format($stats['total_customers']) ?></div>
                        <div class="text-white/70 text-sm">+<?= $stats['new_customers'] ?> this month</div>
                    </div>

                    <!-- Menu Items Card -->
                    <div class="gradient-card from-brand-yellow to-brand-amber rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Menu Items</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= number_format($stats['total_menu_items']) ?></div>
                        <div class="text-white/70 text-sm"><?= $stats['available_menu_items'] ?> available</div>
                    </div>
                </div>

                <!-- Today's Statistics -->
                <div class="grid md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-green-500">
                        <h3 class="text-sm font-medium text-gray-500">Today's Revenue</h3>
                        <p class="text-2xl font-bold text-gray-900">$<?= number_format($stats['today_revenue'], 2) ?></p>
                    </div>
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-blue-500">
                        <h3 class="text-sm font-medium text-gray-500">Today's Orders</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['today_orders']) ?></p>
                    </div>
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-purple-500">
                        <h3 class="text-sm font-medium text-gray-500">Today's Reservations</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['today_reservations']) ?></p>
                    </div>
                    <div class="bg-white rounded-xl shadow-md p-6 border-l-4 border-yellow-500">
                        <h3 class="text-sm font-medium text-gray-500">Pending Reservations</h3>
                        <p class="text-2xl font-bold text-gray-900"><?= number_format($stats['pending_reservations']) ?></p>
                    </div>
                </div>

                <!-- Charts and Analytics Section -->
                <div class="grid lg:grid-cols-2 gap-8 mb-8">
                    <!-- Revenue Chart -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            Monthly Revenue (Last 6 Months)
                        </h3>
                        <canvas id="revenueChart" width="400" height="200"></canvas>
                    </div>

                    <!-- Order Status Distribution -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            Order Status Distribution
                        </h3>
                        <div class="space-y-4">
                            <?php
                            $status_colors = [
                                'pending' => 'bg-yellow-500',
                                'confirmed' => 'bg-blue-500',
                                'preparing' => 'bg-purple-500',
                                'ready' => 'bg-green-500',
                                'delivered' => 'bg-gray-500',
                                'cancelled' => 'bg-red-500'
                            ];
                            
                            foreach ($stats['order_status'] as $status => $count):
                                $percentage = $stats['total_orders'] > 0 ? ($count / $stats['total_orders']) * 100 : 0;
                            ?>
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="w-4 h-4 <?= $status_colors[$status] ?? 'bg-gray-400' ?> rounded mr-3"></div>
                                        <span class="text-sm font-medium text-gray-700"><?= ucfirst($status) ?></span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-sm text-gray-600 mr-2"><?= $count ?></span>
                                        <div class="w-20 bg-gray-200 rounded-full h-2">
                                            <div class="<?= $status_colors[$status] ?? 'bg-gray-400' ?> h-2 rounded-full" style="width: <?= $percentage ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Popular Items -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                        Most Popular Menu Items
                    </h3>
                    <div class="grid md:grid-cols-5 gap-4">
                        <?php foreach ($popular_items as $index => $item): ?>
                            <div class="text-center p-4 rounded-xl bg-gray-50 hover-lift">
                                <div class="w-8 h-8 bg-gradient-to-r from-brand-yellow to-brand-amber text-white rounded-full flex items-center justify-center mx-auto mb-2 text-sm font-bold">
                                    <?= $index + 1 ?>
                                </div>
                                <h4 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($item['name']) ?></h4>
                                <p class="text-xs text-gray-500"><?= $item['total_ordered'] ?> orders</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Activity Section -->
                <div class="grid lg:grid-cols-2 gap-8">
                    <!-- Recent Orders -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                            <h3 class="text-white font-semibold text-lg flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                                Recent Orders
                            </h3>
                        </div>
                        <div class="p-6">
                            <?php if (empty($recent_orders)): ?>
                                <p class="text-gray-500 text-center py-4">No orders yet</p>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($recent_orders as $order): ?>
                                        <div class="flex items-center justify-between p-4 border border-gray-200 rounded-xl hover:bg-gray-50 transition">
                                            <div>
                                                <p class="font-semibold text-gray-900">Order #<?= $order['id'] ?></p>
                                                <p class="text-sm text-gray-500"><?= htmlspecialchars($order['full_name']) ?></p>
                                                <p class="text-xs text-gray-400"><?= date('M j, g:i A', strtotime($order['created_at'])) ?></p>
                                            </div>
                                            <div class="text-right">
                                                <p class="font-bold text-gray-900">$<?= number_format($order['total_amount'], 2) ?></p>
                                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                                                    <?php
                                                    $status_colors = [
                                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                                        'confirmed' => 'bg-blue-100 text-blue-800',
                                                        'preparing' => 'bg-purple-100 text-purple-800',
                                                        'ready' => 'bg-green-100 text-green-800',
                                                        'delivered' => 'bg-gray-100 text-gray-800',
                                                        'cancelled' => 'bg-red-100 text-red-800'
                                                    ];
                                                    echo $status_colors[$order['status']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>">
                                                    <?= ucfirst($order['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4 text-center">
                                    <a href="orders.php" class="text-brand-yellow hover:text-brand-amber font-medium transition-colors">View All Orders →</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Reservations -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                            <h3 class="text-white font-semibold text-lg flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                                </svg>
                                Recent Reservations
                            </h3>
                        </div>
                        <div class="p-6">
                            <?php if (empty($recent_reservations)): ?>
                                <p class="text-gray-500 text-center py-4">No reservations yet</p>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($recent_reservations as $reservation): ?>
                                        <div class="flex items-center justify-between p-4 border border-gray-200 rounded-xl hover:bg-gray-50 transition">
                                            <div>
                                                <p class="font-semibold text-gray-900"><?= htmlspecialchars($reservation['name']) ?></p>
                                                <p class="text-sm text-gray-500"><?= date('M j, Y', strtotime($reservation['date'])) ?> at <?= date('g:i A', strtotime($reservation['time'])) ?></p>
                                                <p class="text-xs text-gray-400"><?= $reservation['guests'] ?> guests</p>
                                            </div>
                                            <div class="text-right">
                                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                                                    <?php
                                                    $status_colors = [
                                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                                        'confirmed' => 'bg-green-100 text-green-800',
                                                        'cancelled' => 'bg-red-100 text-red-800'
                                                    ];
                                                    echo $status_colors[$reservation['status']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>">
                                                    <?= ucfirst($reservation['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4 text-center">
                                    <a href="reservations.php" class="text-brand-yellow hover:text-brand-amber font-medium transition-colors">View All Reservations →</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const monthlyData = <?= json_encode(array_reverse($monthly_data)) ?>;
        
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: monthlyData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Revenue ($)',
                    data: monthlyData.map(item => parseFloat(item.revenue || 0)),
                    borderColor: '#F59E0B',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Auto-refresh dashboard data every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Enhanced interactions
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.gradient-card, .hover-lift');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.transition = 'transform 0.2s ease-in-out';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        // Add click-to-copy functionality for order IDs
        function copyOrderId(orderId) {
            navigator.clipboard.writeText('Order #' + orderId).then(function() {
                const toast = document.createElement('div');
                toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50';
                toast.textContent = 'Order ID copied to clipboard!';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 2000);
            });
        }
    </script>
</body>
</html>