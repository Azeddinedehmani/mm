<!-- resources/views/partials/notification-dropdown.blade.php -->
<div class="dropdown">
    <button class="btn btn-outline-primary position-relative" type="button" 
            id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-bell"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
              id="notification-count" style="display: none;">
            0
        </span>
    </button>
    
    <div class="dropdown-menu dropdown-menu-end notification-dropdown" 
         aria-labelledby="notificationDropdown" style="width: 350px; max-height: 400px; overflow-y: auto;">
        
        <div class="dropdown-header d-flex justify-content-between align-items-center">
            <span>Notifications</span>
            <button type="button" id="mark-all-read" class="btn btn-link btn-sm text-decoration-none p-0">
                Tout marquer comme lu
            </button>
        </div>
        
        <div id="notification-list">
            <!-- Notifications will be loaded here via AJAX -->
        </div>
        
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-center" href="{{ route('notifications.index') }}">
            <i class="fas fa-eye me-1"></i>Voir toutes les notifications
        </a>
    </div>
</div>
<!-- resources/views/partials/notification-dropdown.blade.php -->
<div class="dropdown me-3">
    <button class="btn btn-outline-secondary position-relative" type="button" data-bs-toggle="dropdown" id="notificationDropdown">
        <i class="fas fa-bell"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notification-count" style="display: none;">
            0
        </span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end" style="width: 350px; max-height: 400px; overflow-y: auto;">
        <li>
            <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                <span>Notifications</span>
                <button class="btn btn-sm btn-link p-0" onclick="markAllAsRead()" style="font-size: 0.8em;">
                    Tout marquer comme lu
                </button>
            </h6>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li id="notifications-container">
            <div class="text-center py-3">
                <i class="fas fa-spinner fa-spin"></i>
                <div>Chargement...</div>
            </div>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item text-center" href="{{ route('notifications.index') }}">
                <i class="fas fa-eye me-1"></i> Voir toutes les notifications
            </a>
        </li>
    </ul>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load notifications when dropdown is opened
    document.getElementById('notificationDropdown').addEventListener('click', function() {
        loadNotifications();
    });

    // Load notification count on page load
    updateNotificationCount();
    
    // Auto-refresh every 30 seconds
    setInterval(updateNotificationCount, 30000);
});

function loadNotifications() {
    fetch('{{ route("notifications.recent") }}')
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('notifications-container');
            
            if (data.notifications.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-check-circle"></i>
                        <div>Aucune notification</div>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = '';
            
            data.notifications.forEach(notification => {
                const item = document.createElement('li');
                item.innerHTML = `
                    <a class="dropdown-item ${notification.is_read ? '' : 'bg-light'}" href="#" onclick="markAsRead(${notification.id})">
                        <div class="d-flex">
                            <div class="me-2">
                                <i class="${notification.type_icon} text-${getPriorityColor(notification.priority)}"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold">${notification.title}</div>
                                <div class="text-muted small">${notification.message}</div>
                                <div class="text-muted small">${notification.created_at}</div>
                            </div>
                            ${!notification.is_read ? '<div class="ms-2"><span class="badge bg-primary rounded-pill">•</span></div>' : ''}
                        </div>
                    </a>
                `;
                container.appendChild(item);
            });
            
            updateNotificationCount();
        })
        .catch(error => {
            console.error('Erreur lors du chargement des notifications:', error);
            document.getElementById('notifications-container').innerHTML = `
                <div class="text-center py-3 text-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>Erreur de chargement</div>
                </div>
            `;
        });
}

function updateNotificationCount() {
    fetch('{{ route("notifications.count") }}')
        .then(response => response.json())
        .then(data => {
            const badge = document.getElementById('notification-count');
            if (data.count > 0) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(error => console.error('Erreur lors de la mise à jour du compteur:', error));
}

function markAsRead(notificationId) {
    fetch(`{{ url('notifications') }}/${notificationId}/mark-read`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
            updateNotificationCount();
        }
    })
    .catch(error => console.error('Erreur:', error));
}

function markAllAsRead() {
    fetch('{{ route("notifications.mark-all-read") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
            updateNotificationCount();
        }
    })
    .catch(error => console.error('Erreur:', error));
}

function getPriorityColor(priority) {
    switch(priority) {
        case 'high': return 'danger';
        case 'medium': return 'warning';
        case 'low': return 'info';
        default: return 'secondary';
    }
}
</script>
<script>
// Notification functionality
document.addEventListener('DOMContentLoaded', function() {
    loadNotifications();
    
    // Refresh notifications every 30 seconds
    setInterval(loadNotifications, 30000);
    
    // Mark all as read functionality
    document.getElementById('mark-all-read').addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Empêche la fermeture du dropdown
        markAllAsRead();
    });
});

function loadNotifications() {
    fetch('{{ route("notifications.recent") }}')
        .then(response => response.json())
        .then(data => {
            updateNotificationDropdown(data);
        })
        .catch(error => console.error('Error loading notifications:', error));
}

function updateNotificationDropdown(data) {
    const countBadge = document.getElementById('notification-count');
    const notificationList = document.getElementById('notification-list');
    
    // Update count badge
    if (data.unread_count > 0) {
        countBadge.textContent = data.unread_count;
        countBadge.style.display = 'inline';
    } else {
        countBadge.style.display = 'none';
    }
    
    // Update notification list
    if (data.notifications.length === 0) {
        notificationList.innerHTML = `
            <div class="dropdown-item-text text-center text-muted py-3">
                <i class="fas fa-bell-slash"></i><br>
                Aucune notification
            </div>
        `;
    } else {
        notificationList.innerHTML = data.notifications.map(notification => `
            <div class="dropdown-item ${!notification.is_read ? 'bg-light' : ''}" style="cursor: pointer;"
                 onclick="handleNotificationClick(${notification.id}, '${notification.action_url || ''}')">
                <div class="d-flex align-items-start">
                    <i class="${notification.type_icon} me-2 text-primary"></i>
                    <div class="flex-grow-1">
                        <h6 class="dropdown-header p-0 mb-1 ${!notification.is_read ? 'fw-bold' : ''}">${notification.title}</h6>
                        <p class="mb-1 small text-muted">${notification.message}</p>
                        <small class="text-muted">${notification.created_at}</small>
                    </div>
                    ${!notification.is_read ? '<span class="badge bg-primary ms-1">Nouveau</span>' : ''}
                </div>
            </div>
        `).join('');
    }
}

function handleNotificationClick(notificationId, actionUrl) {
    // Mark as read first
    markAsRead(notificationId).then(() => {
        // Then redirect if there's an action URL
        if (actionUrl && actionUrl !== '' && actionUrl !== 'null') {
            window.location.href = actionUrl;
        }
    });
}

function markAsRead(notificationId) {
    return fetch(`/notifications/${notificationId}/mark-read`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
        return data;
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
        return { success: false };
    });
}

function markAllAsRead() {
    fetch('{{ route("notifications.mark-all-read") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
        }
    })
    .catch(error => console.error('Error marking all notifications as read:', error));
}
</script>

<style>
.notification-dropdown .dropdown-item {
    white-space: normal;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f8f9fa;
}

.notification-dropdown .dropdown-item:last-child {
    border-bottom: none;
}

.notification-dropdown .dropdown-item:hover {
    background-color: #f8f9fa;
}

.notification-dropdown .dropdown-header {
    padding: 0.75rem 1rem;
}
</style>