
// api/script.js
// Toggle the "New Post" form (hidden by default) with HTMX re-initialization

function initNewPostToggle() {
  var newPostButton = document.getElementById("newPostButton");
  var newPostForm = document.getElementById("newPostForm");
  if (newPostButton && newPostForm) {
    // Replace the button to remove old listeners
    var newButtonClone = newPostButton.cloneNode(true);
    newPostButton.parentNode.replaceChild(newButtonClone, newPostButton);
    newPostButton = newButtonClone;

    newPostButton.addEventListener("click", function() {
      if (newPostForm.style.display === "none" || newPostForm.style.display === "") {
        newPostForm.style.display = "block";
        newPostButton.textContent = "Close";
      } else {
        newPostForm.style.display = "none";
        newPostButton.textContent = "New Post";
      }
    });
  }
}

// Initialize on page load
document.addEventListener("DOMContentLoaded", initNewPostToggle);

// Reinit after HTMX swaps
document.addEventListener("htmx:afterSwap", initNewPostToggle);
