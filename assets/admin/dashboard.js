/* Dashboard heads-up widget. Needs confurDashboard (ajaxUrl, nonce, userId), set by ConfurHeadsUp.
 * Loaded only on that screen. Was inline in the PHP until the Phase 4 refactor. */
jQuery(document).ready(function($) {
    // Load saved collapse state from user meta
    function loadCollapseState() {
        var state = localStorage.getItem("confur_headsup_collapse_" + confurDashboard.userId);
        if (state) {
            try {
                var collapsed = JSON.parse(state);
                collapsed.forEach(function(committeeNum) {
                    $(".confur-committee-item[data-committee=\"" + committeeNum + "\"]").addClass("collapsed");
                });
            } catch(e) {
                console.error("Failed to parse collapse state", e);
            }
        }
    }
    
    // Save collapse state
    function saveCollapseState() {
        var collapsed = [];
        $(".confur-committee-item.collapsed").each(function() {
            collapsed.push($(this).data("committee"));
        });
        localStorage.setItem("confur_headsup_collapse_" + confurDashboard.userId, JSON.stringify(collapsed));
    }
    
    // Toggle committee collapse
    $(document).on("click", ".confur-committee-title", function(e) {
        e.preventDefault();
        $(this).closest(".confur-committee-item").toggleClass("collapsed");
        saveCollapseState();
    });
    
    // Load state on page load
    loadCollapseState();
    
    // Handle refresh button
    $("#confur-refresh-btn").on("click", function() {
        var $btn = $(this);
        var $content = $("#confur-headsup-content");
        
        // Prevent double clicks
        if ($btn.prop("disabled")) {
            return;
        }
        
        // Show loading state
        $btn.prop("disabled", true).addClass("rotating");
        $content.addClass("loading");
        
        $.ajax({
            url: confurDashboard.ajaxUrl,
            type: "POST",
            data: {
                action: "confur_refresh_headsup",
                nonce: confurDashboard.nonce
            },
            success: function(response) {
                if (response.success) {
                    $content.html(response.data.content);
                    
                    // Restore collapse state after refresh
                    loadCollapseState();
                    
                    // Update timestamp
                    var date = new Date(response.data.updated);
                    var formatted = date.toLocaleString("en-US", {
                        month: "short",
                        day: "numeric", 
                        year: "numeric",
                        hour: "numeric",
                        minute: "2-digit",
                        hour12: true
                    });
                    $("#confur-update-time").text(formatted);
                } else {
                    alert("Failed to refresh: " + (response.data.message || "Unknown error"));
                }
            },
            error: function() {
                alert("Failed to refresh widget. Please try again.");
            },
            complete: function() {
                $btn.prop("disabled", false).removeClass("rotating");
                $content.removeClass("loading");
            }
        });
    });
});
