console.log("Script has started.");
var timeSpent = 0;
var startTime = Date.now();

// Function to handle visibility change
function handleVisibilityChange() {
    if (document.hidden) {
        var endTime = Date.now();
        timeSpent += (endTime - startTime) / 1000; // Convert milliseconds to seconds

        sendTimeSpent('interval');
        console.log("Page is now hidden, time spent: " + timeSpent);
    } else {
        startTime = Date.now();
        console.log("Page is now visible.");
    }
}

// Function to send the time spent to the server
function sendTimeSpent(type) {
    var url = '<?php echo admin_url('admin-ajax.php'); ?>';
    var data = 'action=gather_visitor_data&time_spent=' + timeSpent + '&type=' + type;

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: data
    });
}

// Listen for visibility change events
document.addEventListener('visibilitychange', handleVisibilityChange, false);

// On unload, send the final time spent
window.onbeforeunload = function() {
    if (!document.hidden) {
        var endTime = Date.now();
        timeSpent += (endTime - startTime) / 1000; // Convert milliseconds to seconds
    }

    navigator.sendBeacon('<?php echo admin_url('admin-ajax.php'); ?>', JSON.stringify({
        action: 'gather_visitor_data',
        time_spent: timeSpent,
        type: 'unload'
    }));
};
