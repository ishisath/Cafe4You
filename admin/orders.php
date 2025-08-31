<?php
// admin/orders.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = sanitize($_POST['status']);
    
    $update_query = "UPDATE orders SET status = ? WHERE id = ?";
    $update_stmt = $db->prepare($update_query);
    if ($update_stmt->execute([$new_status, $order_id])) {
        showMessage('Order status updated successfully!');
    } else {
        showMessage('Failed to update order status', 'error');
    }
}

// Get orders with enhanced statistics
$orders_query = "SELECT o.*, u.full_name, u.email, COUNT(oi.id) as item_count 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN order_items oi ON o.id = oi.order_id 
                GROUP BY o.id 
                ORDER BY o.created_at DESC";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->execute();
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate order statistics
$stats = [];
$stats['total_orders'] = count($orders);
$stats['total_revenue'] = array_sum(array_column($orders, 'total_amount'));
$stats['avg_order_value'] = $stats['total_orders'] > 0 ? $stats['total_revenue'] / $stats['total_orders'] : 0;

// Status breakdown
$status_counts = [];
foreach ($orders as $order) {
    $status = $order['status'];
    $status_counts[$status] = ($status_counts[$status] ?? 0) + 1;
}

// Today's orders
$today_orders = array_filter($orders, function($order) {
    return date('Y-m-d', strtotime($order['created_at'])) === date('Y-m-d');
});
$stats['today_orders'] = count($today_orders);
$stats['today_revenue'] = array_sum(array_column($today_orders, 'total_amount'));

// Get order details if requested
$order_details = null;
if (isset($_GET['view'])) {
    $order_id = (int)$_GET['view'];
    
    $details_query = "SELECT o.*, u.full_name, u.email, u.phone as user_phone,
                     oi.quantity, oi.price, mi.name as item_name 
                     FROM orders o 
                     JOIN users u ON o.user_id = u.id 
                     JOIN order_items oi ON o.id = oi.order_id 
                     JOIN menu_items mi ON oi.menu_item_id = mi.id 
                     WHERE o.id = ?";
    $details_stmt = $db->prepare($details_query);
    $details_stmt->execute([$order_id]);
    $order_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Management - Admin</title>
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
                    <a href="orders.php" class="nav-item active flex items-center px-4 py-3 text-brand-yellow bg-yellow-50 transition-all duration-300 rounded-lg mx-2 font-medium">
                        <svg class="w-5 h-5 mr-3 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                        <div class="w-12 h-12 bg-gradient-to-br from-orange-400 to-orange-600 rounded-2xl flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-800">Order Management</h1>
                            <p class="text-gray-600 mt-1">Track and manage customer orders in real-time</p>
                        </div>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Orders Card -->
                    <div class="gradient-card from-blue-500 to-blue-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Orders</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_orders'] ?></div>
                        <div class="text-white/70 text-sm">All customer orders</div>
                    </div>

                    <!-- Total Revenue Card -->
                    <div class="gradient-card from-green-500 to-green-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Revenue</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2">$<?= number_format($stats['total_revenue'], 0) ?></div>
                        <div class="text-white/70 text-sm">From all orders</div>
                    </div>

                    <!-- Today's Orders Card -->
                    <div class="gradient-card from-purple-500 to-purple-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Today's Orders</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['today_orders'] ?></div>
                        <div class="text-white/70 text-sm">Orders placed today</div>
                    </div>

                    <!-- Average Order Value Card -->
                    <div class="gradient-card from-brand-yellow to-brand-amber rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Avg Order Value</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2">$<?= number_format($stats['avg_order_value'], 2) ?></div>
                        <div class="text-white/70 text-sm">Per order average</div>
                    </div>
                </div>

                <!-- Status Overview -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
                    <?php
                    $status_colors = [
                        'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                        'confirmed' => 'bg-blue-100 text-blue-800 border-blue-300',
                        'preparing' => 'bg-purple-100 text-purple-800 border-purple-300',
                        'ready' => 'bg-green-100 text-green-800 border-green-300',
                        'delivered' => 'bg-gray-100 text-gray-800 border-gray-300',
                        'cancelled' => 'bg-red-100 text-red-800 border-red-300'
                    ];
                    
                    foreach (['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled'] as $status):
                        $count = $status_counts[$status] ?? 0;
                    ?>
                        <div class="bg-white rounded-xl shadow-md p-4 border-l-4 <?= $status_colors[$status] ?>">
                            <h3 class="text-sm font-medium text-gray-500 capitalize"><?= $status ?></h3>
                            <p class="text-2xl font-bold text-gray-900"><?= $count ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Orders Table -->
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                                <h3 class="text-white font-semibold text-lg">Customer Orders</h3>
                            </div>
                            <div class="text-white/90 text-sm">
                                <span class="bg-white/20 px-3 py-1 rounded-full">
                                    <?= count($orders) ?> Total Orders
                                </span>
                            </div>
                        </div>
                        <p class="text-white/80 text-sm mt-1">Manage and track all customer orders (<?= count($orders) ?> orders)</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ORDER ID</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">CUSTOMER</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">DATE & TIME</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ITEMS</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">TOTAL</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">STATUS</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php foreach ($orders as $order): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="w-10 h-10 bg-gradient-to-br from-brand-yellow to-brand-amber rounded-xl flex items-center justify-center text-white font-bold">
                                                #<?= $order['id'] ?>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center mr-3">
                                                    <span class="text-white font-medium text-sm">
                                                        <?= strtoupper(substr($order['full_name'], 0, 2)) ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($order['full_name']) ?></div>
                                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($order['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-semibold text-gray-900"><?= date('M j, Y', strtotime($order['created_at'])) ?></div>
                                            <div class="text-xs text-gray-500"><?= date('g:i A', strtotime($order['created_at'])) ?></div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?= $order['item_count'] ?> items
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-bold text-gray-900">$<?= number_format($order['total_amount'], 2) ?></div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <select name="status" onchange="confirmStatusChange(this)" 
                                                        class="text-sm border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-yellow focus:border-transparent p-2 font-semibold <?php
                                                        echo match($order['status']) {
                                                            'pending' => 'bg-yellow-50 text-yellow-800',
                                                            'confirmed' => 'bg-blue-50 text-blue-800',
                                                            'preparing' => 'bg-purple-50 text-purple-800',
                                                            'ready' => 'bg-green-50 text-green-800',
                                                            'delivered' => 'bg-gray-50 text-gray-800',
                                                            'cancelled' => 'bg-red-50 text-red-800',
                                                            default => 'bg-gray-50 text-gray-800'
                                                        };
                                                        ?>">
                                                    <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                    <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                    <option value="preparing" <?= $order['status'] === 'preparing' ? 'selected' : '' ?>>Preparing</option>
                                                    <option value="ready" <?= $order['status'] === 'ready' ? 'selected' : '' ?>>Ready</option>
                                                    <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                                    <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <a href="orders.php?view=<?= $order['id'] ?>" 
                                               class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($orders)): ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-700 mb-2">No Orders Yet</h3>
                        <p class="text-gray-500">Orders from customers will appear here once they start placing orders.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Enhanced Order Details Modal -->
    <?php if ($order_details): ?>
        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4" id="order-modal">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
                <!-- Modal Header -->
                <div class="sticky top-0 bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4 rounded-t-2xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-white">Order Details</h3>
                                <p class="text-white/80 text-sm">Order #<?= $order_details[0]['id'] ?></p>
                            </div>
                        </div>
                        <a href="orders.php" class="text-white/80 hover:text-white" title="Close">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                    </div>
                </div>
                
                <!-- Modal Body -->
                <div class="p-6">
                    <!-- Customer Information -->
                    <div class="mb-8">
                        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Customer Information
                        </h4>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-2xl p-6 space-y-3">
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-600">Name:</span>
                                <span class="text-sm text-gray-900"><?= htmlspecialchars($order_details[0]['full_name']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-600">Email:</span>
                                <span class="text-sm text-gray-900"><?= htmlspecialchars($order_details[0]['email']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-600">Phone:</span>
                                <span class="text-sm text-gray-900"><?= htmlspecialchars($order_details[0]['phone']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-600">Date:</span>
                                <span class="text-sm text-gray-900"><?= date('M j, Y g:i A', strtotime($order_details[0]['created_at'])) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-600">Status:</span>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                    <?php
                                    echo match($order_details[0]['status']) {
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'confirmed' => 'bg-blue-100 text-blue-800',
                                        'preparing' => 'bg-purple-100 text-purple-800',
                                        'ready' => 'bg-green-100 text-green-800',
                                        'delivered' => 'bg-gray-100 text-gray-800',
                                        'cancelled' => 'bg-red-100 text-red-800',
                                        default => 'bg-gray-100 text-gray-800'
                                    };
                                    ?>">
                                    <?= ucfirst($order_details[0]['status']) ?>
                                </span>
                            </div>
                            <div class="flex">
                                <span class="text-sm font-medium text-gray-600 w-24">Address:</span>
                                <span class="text-sm text-gray-900 flex-1"><?= htmlspecialchars($order_details[0]['delivery_address']) ?></span>
                            </div>
                            <?php if ($order_details[0]['special_instructions']): ?>
                            <div class="flex">
                                <span class="text-sm font-medium text-gray-600 w-24">Notes:</span>
                                <span class="text-sm text-gray-900 flex-1"><?= htmlspecialchars($order_details[0]['special_instructions']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Order Items -->
                    <div>
                        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                            Order Items
                        </h4>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-2xl p-6">
                            <div class="space-y-4">
                                <?php foreach ($order_details as $item): ?>
                                    <div class="flex items-center justify-between p-4 bg-white rounded-xl shadow-sm border border-gray-200">
                                        <div class="flex-1">
                                            <h5 class="font-semibold text-gray-900"><?= htmlspecialchars($item['item_name']) ?></h5>
                                            <p class="text-sm text-gray-500">Quantity: <?= $item['quantity'] ?> × $<?= number_format($item['price'], 2) ?></p>
                                        </div>
                                        <div class="text-lg font-bold text-brand-amber">
                                            $<?= number_format($item['price'] * $item['quantity'], 2) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Order Total -->
                            <div class="border-t border-gray-300 mt-6 pt-6">
                                <div class="flex justify-between items-center">
                                    <span class="text-xl font-bold text-gray-900">Order Total</span>
                                    <span class="text-2xl font-bold text-brand-amber">$<?= number_format($order_details[0]['total_amount'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex space-x-4 mt-8 pt-6 border-t border-gray-200">
                        <a href="orders.php" class="flex-1 bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-semibold hover:bg-gray-300 transition-all duration-300 text-center">
                            Close Details
                        </a>
                        <button onclick="window.print()" class="flex-1 bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300">
                            Print Order
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        function confirmStatusChange(selectElement) {
            const form = selectElement.form;
            const newStatus = selectElement.value;
            const orderNumber = form.querySelector('input[name="order_id"]').value;
            
            if (confirm(`Are you sure you want to change order #${orderNumber} status to "${newStatus}"?`)) {
                form.submit();
            } else {
                // Reset to previous value if cancelled
                selectElement.selectedIndex = 0;
            }
        }
        
        // Enhanced modal handling
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && document.getElementById('order-modal')) {
                    window.location.href = 'orders.php';
                }
            });

            // Close modal on background click
            const modal = document.getElementById('order-modal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        window.location.href = 'orders.php';
                    }
                });
            }
        });
    </script>
</body>
</html>