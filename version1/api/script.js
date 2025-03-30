// api/script.js - Reliable toggle for the New Post form with HTMX support

function initNewPostToggle() {
  var newPostButton = document.getElementById("newPostButton");
  var newPostForm = document.getElementById("newPostForm");
  if (newPostButton && newPostForm) {
    // Remove any existing click listeners by cloning the node
    // (This is one way to ensure we don't add duplicate listeners)
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

// Initialize when the page first loads
document.addEventListener("DOMContentLoaded", initNewPostToggle);

// Reinitialize after HTMX swaps content
document.addEventListener("htmx:afterSwap", initNewPostToggle);
