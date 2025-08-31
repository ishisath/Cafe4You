<?php
// admin/reservations.php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $reservation_id = (int)$_POST['reservation_id'];
    $new_status = sanitize($_POST['status']);
    
    $update_query = "UPDATE reservations SET status = ? WHERE id = ?";
    $update_stmt = $db->prepare($update_query);
    if ($update_stmt->execute([$new_status, $reservation_id])) {
        showMessage('Reservation status updated successfully!');
    } else {
        showMessage('Failed to update reservation status', 'error');
    }
}

// Get all reservations
$reservations_query = "SELECT r.*, u.full_name as user_name, u.email as user_email 
                      FROM reservations r 
                      LEFT JOIN users u ON r.user_id = u.id 
                      ORDER BY r.date DESC, r.time DESC";
$reservations_stmt = $db->prepare($reservations_query);
$reservations_stmt->execute();
$reservations = $reservations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate reservation statistics
$stats = [];
$stats['total_reservations'] = count($reservations);
$stats['total_guests'] = array_sum(array_column($reservations, 'guests'));
$stats['avg_party_size'] = $stats['total_reservations'] > 0 ? $stats['total_guests'] / $stats['total_reservations'] : 0;

// Status breakdown
$status_counts = [];
foreach ($reservations as $reservation) {
    $status = $reservation['status'];
    $status_counts[$status] = ($status_counts[$status] ?? 0) + 1;
}

// Today's reservations
$today_reservations = array_filter($reservations, function($reservation) {
    return date('Y-m-d', strtotime($reservation['date'])) === date('Y-m-d');
});
$stats['today_reservations'] = count($today_reservations);
$stats['today_guests'] = array_sum(array_column($today_reservations, 'guests'));

// Upcoming reservations (next 7 days)
$upcoming_reservations = array_filter($reservations, function($reservation) {
    $reservation_date = strtotime($reservation['date']);
    $today = strtotime('today');
    $next_week = strtotime('+7 days', $today);
    return $reservation_date >= $today && $reservation_date <= $next_week && $reservation['status'] !== 'cancelled';
});
$stats['upcoming_reservations'] = count($upcoming_reservations);

// Get reservation details if requested
$reservation_details = null;
if (isset($_GET['view'])) {
    $reservation_id = (int)$_GET['view'];
    
    $details_query = "SELECT r.*, u.full_name as user_name, u.email as user_email 
                     FROM reservations r 
                     LEFT JOIN users u ON r.user_id = u.id 
                     WHERE r.id = ?";
    $details_stmt = $db->prepare($details_query);
    $details_stmt->execute([$reservation_id]);
    $reservation_details = $details_stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate stats for cards
$pending_count = $status_counts['pending'] ?? 0;
$confirmed_count = $status_counts['confirmed'] ?? 0;
$cancelled_count = $status_counts['cancelled'] ?? 0;
$completed_count = $status_counts['completed'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Management - Cafe For You Admin</title>
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
                    <a href="categories.php" class="nav-item flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-all duration-300 rounded-lg mx-2">
                        <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        Categories
                    </a>
                    <a href="reservations.php" class="nav-item active flex items-center px-4 py-3 text-brand-yellow bg-yellow-50 transition-all duration-300 rounded-lg mx-2 font-medium">
                        <svg class="w-5 h-5 mr-3 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                        <div class="w-12 h-12 bg-gradient-to-br from-purple-400 to-purple-600 rounded-2xl flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-800">Reservation Management</h1>
                            <p class="text-gray-600 mt-1">Manage table reservations and track customer bookings</p>
                        </div>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Reservations -->
                    <div class="gradient-card from-purple-500 to-purple-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Reservations</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_reservations'] ?></div>
                        <div class="text-white/70 text-sm">All bookings</div>
                    </div>

                    <!-- Total Guests -->
                    <div class="gradient-card from-blue-500 to-blue-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Total Guests</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['total_guests'] ?></div>
                        <div class="text-white/70 text-sm">Expected diners</div>
                    </div>

                    <!-- Today's Bookings -->
                    <div class="gradient-card from-green-500 to-green-600 rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Today's Bookings</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= $stats['today_reservations'] ?></div>
                        <div class="text-white/70 text-sm">Today's reservations</div>
                    </div>

                    <!-- Average Party Size -->
                    <div class="gradient-card from-brand-yellow to-brand-amber rounded-2xl p-6 text-white hover-lift">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-white/80 text-sm font-medium">Avg Party Size</h3>
                            <svg class="w-8 h-8 text-white/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <div class="text-3xl font-bold mb-2"><?= number_format($stats['avg_party_size'], 1) ?></div>
                        <div class="text-white/70 text-sm">People per booking</div>
                    </div>
                </div>

                <!-- Status Overview -->
                <div class="grid md:grid-cols-4 gap-6 mb-8">
                    <!-- Pending -->
                    <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 border border-yellow-200 rounded-2xl p-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-yellow-800"><?= $pending_count ?></div>
                            <div class="text-sm text-yellow-600 font-medium">Pending</div>
                        </div>
                    </div>

                    <!-- Confirmed -->
                    <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-2xl p-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-800"><?= $confirmed_count ?></div>
                            <div class="text-sm text-green-600 font-medium">Confirmed</div>
                        </div>
                    </div>

                    <!-- Cancelled -->
                    <div class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 rounded-2xl p-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-red-800"><?= $cancelled_count ?></div>
                            <div class="text-sm text-red-600 font-medium">Cancelled</div>
                        </div>
                    </div>

                    <!-- Completed -->
                    <div class="bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 rounded-2xl p-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-gray-800"><?= $completed_count ?></div>
                            <div class="text-sm text-gray-600 font-medium">Completed</div>
                        </div>
                    </div>
                </div>

                <!-- Reservations Table -->
                <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-6 h-6 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                                </svg>
                                <h3 class="text-white font-semibold text-lg">Table Reservations</h3>
                            </div>
                            <div class="text-white/90 text-sm">
                                <span class="bg-white/20 px-3 py-1 rounded-full">
                                    <?= $stats['total_reservations'] ?> Total Bookings
                                </span>
                            </div>
                        </div>
                        <p class="text-white/80 text-sm mt-1">Manage customer table bookings (<?= count($reservations) ?> reservations)</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">RESERVATION ID</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">CUSTOMER</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">DATE & TIME</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">PARTY SIZE</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">CONTACT</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">STATUS</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php if (empty($reservations)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-12 h-12 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                                                </svg>
                                                <h3 class="text-lg font-semibold text-gray-700 mb-2">No Reservations Yet</h3>
                                                <p class="text-gray-500">Customer reservations will appear here once they start booking tables.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $counter = 1; foreach ($reservations as $reservation): ?>
                                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                                            <!-- Reservation ID -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="w-10 h-10 bg-gradient-to-br from-purple-400 to-purple-600 rounded-xl flex items-center justify-center text-white font-bold">
                                                    #<?= $counter++ ?>
                                                </div>
                                            </td>

                                            <!-- Customer Info -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center mr-3">
                                                        <span class="text-white font-medium text-sm">
                                                            <?= strtoupper(substr($reservation['name'], 0, 2)) ?>
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($reservation['name']) ?></div>
                                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($reservation['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Date & Time -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-semibold text-gray-900"><?= date('M j, Y', strtotime($reservation['date'])) ?></div>
                                                <div class="text-xs text-gray-500"><?= date('g:i A', strtotime($reservation['time'])) ?></div>
                                            </td>

                                            <!-- Party Size -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?= $reservation['guests'] ?> guests
                                                </div>
                                            </td>

                                            <!-- Contact -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($reservation['phone']) ?></div>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($reservation['email']) ?></div>
                                            </td>

                                            <!-- Status -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="reservation_id" value="<?= $reservation['id'] ?>">
                                                    <select name="status" onchange="confirmStatusChange(this)" 
                                                            class="text-xs border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-brand-yellow focus:border-transparent p-2 font-medium <?php
                                                            echo match($reservation['status']) {
                                                                'pending' => 'bg-yellow-50 text-yellow-800',
                                                                'confirmed' => 'bg-green-50 text-green-800',
                                                                'cancelled' => 'bg-red-50 text-red-800',
                                                                'completed' => 'bg-gray-50 text-gray-800',
                                                                default => 'bg-gray-50 text-gray-800'
                                                            };
                                                            ?>">
                                                        <option value="pending" <?= $reservation['status'] === 'pending' ? 'selected' : '' ?>>⏳ Pending</option>
                                                        <option value="confirmed" <?= $reservation['status'] === 'confirmed' ? 'selected' : '' ?>>✅ Confirmed</option>
                                                        <option value="cancelled" <?= $reservation['status'] === 'cancelled' ? 'selected' : '' ?>>❌ Cancelled</option>
                                                        <option value="completed" <?= $reservation['status'] === 'completed' ? 'selected' : '' ?>>✨ Completed</option>
                                                    </select>
                                                    <input type="hidden" name="update_status" value="1">
                                                </form>
                                            </td>

                                            <!-- Actions -->
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <a href="reservations.php?view=<?= $reservation['id'] ?>" 
                                                   class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:shadow-lg transition-all duration-300 transform hover:scale-105">
                                                    👁 View Details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Enhanced Reservation Details Modal -->
    <?php if ($reservation_details): ?>
        <div class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4" id="reservation-modal">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-gradient-to-r from-brand-yellow to-brand-amber px-6 py-4 rounded-t-2xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3a1 1 0 011 1v9a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1h3z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-white">Reservation Details</h3>
                                <p class="text-white/80 text-sm">Reservation #<?= $reservation_details['id'] ?></p>
                            </div>
                        </div>
                        <a href="reservations.php" class="text-white/80 hover:text-white" title="Close">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    <!-- Customer Information -->
                    <div class="mb-8">
                        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-brand-yellow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            Customer Information
                        </h4>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-2xl p-6">
                            <div class="grid md:grid-cols-2 gap-6">
                                <div class="space-y-4">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Name</span>
                                        <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars($reservation_details['name']) ?></p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Email</span>
                                        <p class="text-gray-900"><?= htmlspecialchars($reservation_details['email']) ?></p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Phone</span>
                                        <p class="text-gray-900"><?= htmlspecialchars($reservation_details['phone']) ?></p>
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Date</span>
                                        <p class="text-lg font-bold text-gray-900"><?= date('M j, Y', strtotime($reservation_details['date'])) ?></p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Time</span>
                                        <p class="text-gray-900"><?= date('g:i A', strtotime($reservation_details['time'])) ?></p>
                                    </div>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Party Size</span>
                                        <p class="text-gray-900"><?= $reservation_details['guests'] ?> guests</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 pt-6 border-t border-gray-200">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Status</span>
                                        <span class="ml-3 inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                                            <?php
                                            echo match($reservation_details['status']) {
                                                'pending' => 'bg-yellow-100 text-yellow-800',
                                                'confirmed' => 'bg-green-100 text-green-800',
                                                'cancelled' => 'bg-red-100 text-red-800',
                                                'completed' => 'bg-gray-100 text-gray-800',
                                                default => 'bg-gray-100 text-gray-800'
                                            };
                                            ?>">
                                            <?= ucfirst($reservation_details['status']) ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-sm font-semibold text-gray-600">Created</span>
                                        <p class="text-gray-900"><?= date('M j, Y g:i A', strtotime($reservation_details['created_at'])) ?></p>
                                    </div>
                                </div>
                            </div>

                            <?php if ($reservation_details['message']): ?>
                                <div class="mt-6 pt-6 border-t border-gray-200">
                                    <span class="text-sm font-semibold text-gray-600">Special Requests</span>
                                    <div class="mt-2 bg-white rounded-xl p-4 border border-gray-200">
                                        <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($reservation_details['message'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex flex-wrap gap-4 pt-4 border-t border-gray-200">
                        <a href="reservations.php" 
                           class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-3 rounded-xl font-semibold transition-all duration-300 flex items-center space-x-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            <span>Back to Reservations</span>
                        </a>
                        
                        <button onclick="window.print()" 
                                class="bg-gradient-to-r from-brand-yellow to-brand-amber text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all duration-300 transform hover:scale-105 flex items-center space-x-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                            </svg>
                            <span>Print Reservation</span>
                        </button>
                        
                        <a href="mailto:<?= urlencode($reservation_details['email']) ?>?subject=Your Reservation at Cafe For You" 
                           class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-xl font-semibold transition-all duration-300 transform hover:scale-105 flex items-center space-x-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <span>Email Customer</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        function confirmStatusChange(selectElement) {
            const form = selectElement.form;
            const newStatus = selectElement.value;
            const reservationNumber = form.querySelector('input[name="reservation_id"]').value;
            
            if (confirm(`Are you sure you want to change reservation #${reservationNumber} status to "${newStatus}"?`)) {
                form.submit();
            } else {
                // Reset to previous value if cancelled
                location.reload();
            }
        }

        // Enhanced modal handling
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && document.getElementById('reservation-modal')) {
                    window.location.href = 'reservations.php';
                }
            });

            // Close modal on background click
            const modal = document.getElementById('reservation-modal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        window.location.href = 'reservations.php';
                    }
                });
            }

            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                if (row.cells.length > 1) { // Skip empty state row
                    row.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateX(4px)';
                        this.style.boxShadow = '4px 0 8px rgba(252, 211, 77, 0.1)';
                        this.style.transition = 'all 0.2s ease-in-out';
                    });
                    row.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateX(0)';
                        this.style.boxShadow = 'none';
                    });
                }
            });

            // Add status change animations
            const statusSelects = document.querySelectorAll('select[name="status"]');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });
        });

        // Auto-hide success messages
        setTimeout(function() {
            const successMessages = document.querySelectorAll('.bg-green-500');
            successMessages.forEach(function(message) {
                message.style.transition = 'opacity 0.5s ease-out';
                message.style.opacity = '0';
                setTimeout(function() {
                    if (message.parentNode) {
                        message.parentNode.removeChild(message);
                    }
                }, 500);
            });
        }, 4000);
    </script>
</body>
</html>