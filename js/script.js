// Attach CSRF token to same-origin mutating requests.
const medchartFetch = window.fetch.bind(window);
window.fetch = function(resource, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
        options = {...options, headers: {...options.headers, 'X-CSRF-Token': window.MEDCHART_CSRF}};
    }
    return medchartFetch(resource, options).then(response => {
        if (!response.ok) throw new Error('Request failed (' + response.status + '). Please refresh or sign in again.');
        return response;
    });
};
function saveAll() {
    const dob = document.getElementById('dob').value;
    const notes = document.getElementById('notes').value;
    const name = document.getElementById('name').value;

    const params = new URLSearchParams();
    params.append('dob', dob);
    params.append('notes', notes);
    params.append('name', name);

    fetch("ajax/save_medical_data.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
    }).then(() => alert("Saved successfully"));
}

window.onload = function () {
    loadMeds();
    loadTeam();
    loadAllergies();
};

function loadMeds() {
    fetch('ajax/load_medications.php')
        .then(res => res.text())
        .then(data => {
            const [active, inactive] = data.split('<!--SPLIT-->');
            document.getElementById('medications-container').innerHTML = active;
            document.getElementById('inactive-medications-container').innerHTML = inactive;
            initDragAndDrop(); // Re-initialize after loading content
        });
}

function addMedication() {
    const name = document.getElementById('new-med-name').value;
    const dose = document.getElementById('new-med-dose').value;
    const frequency = document.getElementById('new-med-frequency').value;

    const params = new URLSearchParams();
    params.append('name', name);
    params.append('dose', dose);
    params.append('frequency', frequency);

    const url = document.getElementById('addMedBtn').dataset.editing === "true"
        ? 'ajax/update_medication.php'
        : 'ajax/add_medication.php';

    if (document.getElementById('addMedBtn').dataset.editing === "true") {
        params.append('id', document.getElementById('addMedBtn').dataset.editId);
    }

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    }).then(() => {
        clearMedInputs();
        loadMeds();
    });
}

function clearMedInputs() {
    document.getElementById('new-med-name').value = '';
    document.getElementById('new-med-dose').value = '';
    document.getElementById('new-med-frequency').value = '';
    const btn = document.getElementById('addMedBtn'); // Use ID selector
    btn.textContent = "Add Medication";
    btn.dataset.editing = "false";
    btn.removeAttribute("data-edit-id");
}

function editMedication(med) { // Note: 'med' parameter should be the ID or object passed from the HTML onclick call
    // This function assumes 'med' is an object with properties {id, name, dose, frequency}
    document.getElementById('new-med-name').value = med.name || '';
    document.getElementById('new-med-dose').value = med.dose || '';
    document.getElementById('new-med-frequency').value = med.frequency || '';

    const btn = document.getElementById('addMedBtn'); // Use ID selector
    btn.textContent = "Update Medication";
    btn.dataset.editing = "true";
    btn.dataset.editId = med.id;
}

function toggleMedication(id) {
    fetch('ajax/toggle_medication.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+encodeURIComponent(id)}).then(loadMeds);
}

function deleteMedication(id) {
    fetch('ajax/delete_medication.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+encodeURIComponent(id)}).then(loadMeds);
}

function loadTeam() {
    fetch('ajax/load_team.php')
        .then(res => res.text())
        .then(data => {
            document.getElementById('medical-team-container').innerHTML = data;
            initDragAndDrop(); // Re-initialize after loading content
        });
}

function editTeam(row) { // Note: 'row' parameter should be the object passed from the HTML onclick call
    // This function assumes 'row' is an object with properties {id, name, phone}
    document.getElementById('team-name').value = row.name || '';
    document.getElementById('team-role').value = row.phone || '';

    const btn = document.querySelector('[onclick="addTeamMember()"]'); // Keep this if updating HTML is hard
    btn.textContent = "Update Team Member";
    btn.dataset.editing = "true";
    btn.dataset.editId = row.id;
}

function addTeamMember() {
    const name = document.getElementById('team-name').value;
    const phone = document.getElementById('team-role').value;

    const params = new URLSearchParams();
    params.append('name', name);
    params.append('role', phone); // Assuming 'role' is for display name, 'phone' is for phone
    params.append('phone', phone);

    const btn = document.querySelector('[onclick="addTeamMember()"]'); // Keep this if updating HTML is hard
    const url = btn.dataset.editing === "true"
        ? 'ajax/update_team_member.php'
        : 'ajax/add_team_member.php';

    if (btn.dataset.editing === "true") {
        params.append('id', btn.dataset.editId);
    }

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    }).then(() => {
        document.getElementById('team-name').value = '';
        document.getElementById('team-role').value = '';
        btn.textContent = "Add Team Member";
        btn.dataset.editing = "false";
        btn.removeAttribute("data-edit-id");
        loadTeam();
    });
}

function deleteTeamMember(id) {
    fetch('ajax/delete_team_member.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+encodeURIComponent(id)}).then(loadTeam);
}

function loadAllergies() {
    fetch('ajax/load_allergies.php')
        .then(res => res.text())
        .then(html => {
            document.getElementById('allergy-container').innerHTML = html;
            initDragAndDrop(); // Re-initialize after loading content
        });
}

function addAllergy() {
    const input = document.getElementById('allergy-input');
    const value = input.value.trim();
    if (!value) return;

    const btn = document.getElementById('allergy-btn'); // Use ID selector
    const editing = btn.dataset.editing === "true";
    const params = new URLSearchParams();
    params.append('allergy', value);

    let url = 'ajax/add_allergy.php';
    if (editing) {
        url = 'ajax/update_allergy.php';
        params.append('id', btn.dataset.editId);
    }

    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    }).then(() => {
        input.value = '';
        btn.textContent = "Add Allergy";
        btn.dataset.editing = "false";
        btn.removeAttribute("data-edit-id");
        loadAllergies();
    });
}

function deleteAllergy(id) {
    fetch('ajax/delete_allergy.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${id}`
    }).then(loadAllergies);
}

function editAllergy(id, text) {
    const input = document.getElementById('allergy-input');
    input.value = text;
    const btn = document.getElementById('allergy-btn'); // Use ID selector
    btn.textContent = "Update Allergy";
    btn.dataset.editing = "true";
    btn.dataset.editId = id;
}

// This function saves the order of items in a given container to the server
function saveOrder(container, url) {
    const ids = Array.from(container.children).map(el => el.getAttribute('data-id'));
    // console.log("Saving order:", ids); // Debug log
    fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'order=' + ids.join(',')
    })
    .then(response => response.text()) // Log response if needed
    .then(text => {
        // console.log("Order saved response:", text); // Debug log
    })
    .catch(error => {
        console.error("Error saving order:", error);
    });
}

/**
 * Initializes SortableJS for all relevant containers.
 * This function should be called whenever the content of these containers is updated/reloaded.
 */
function initDragAndDrop() {
    const lists = [
        { selector: '#medications-container', url: 'ajax/save_med_order.php' },
        { selector: '#inactive-medications-container', url: 'ajax/save_med_order.php' }, // Assuming same endpoint handles both
        { selector: '#medical-team-container', url: 'ajax/save_team_order.php' },
        { selector: '#allergy-container', url: 'ajax/save_allergy_order.php' }
    ];

    lists.forEach(({ selector, url }) => {
        const container = document.querySelector(selector);
        if (!container) {
            // console.warn(`Container ${selector} not found.`); // Debug log
            return; // Skip if container doesn't exist
        }

        // Try to get the existing Sortable instance
        const existingInstance = container._sortableInstance;

        if (existingInstance) {
            // Destroy the previous instance if it exists to avoid conflicts
            // console.log(`Destroying old Sortable instance for ${selector}`); // Debug log
            existingInstance.destroy();
        }

        // Create the new Sortable instance with mobile-optimized settings
        // console.log(`Creating new Sortable instance for ${selector}`); // Debug log
        const sortableInstance = Sortable.create(container, {
            animation: 150, // Smooth animation duration
            ghostClass: 'sortable-ghost', // Styling for the ghost element
            chosenClass: 'sortable-chosen', // Styling for the selected element
            dragClass: 'sortable-drag',    // Styling for the element being dragged
            handle: '.drag-handle',
            filter: '#mobile-scroll-helper, button:not(.drag-handle), input, a',  // Ignore touches on scroll helper
            preventOnFilter: false,           // Allow default behavior (scrolling) on filter
            // --- Mobile Optimization Options ---
            touchStartThreshold: 3,      // Pixels moved before drag starts (prevents accidental triggers)
            delay: 0,                     // No initial delay for touch start (immediate response)
            delayOnTouchOnly: true,       // Apply delay only on touch devices (optional, since delay is 0)
            forceFallback: false,         // Allow native HTML5 drag/drop first, fallback if needed
            fallbackOnBody: true,         // Attach the fake element to the body during drag
            removeCloneOnHide: true,      // Clean up cloned elements after drag ends
            // --- End Mobile Options ---
            draggable: '.med-row, .team-row, .allergy-row', // Specify which children are draggable
            onEnd: function (/**Event*/ evt) {
                // This function is called when the drag operation ends
                // console.log("Drag ended in container:", selector); // Debug log
                // Get the new order of IDs
                const ids = Array.from(container.children)
                    .filter(child => child.hasAttribute('data-id')) // Ensure it has the required attribute
                    .map(child => child.getAttribute('data-id')); // Extract the ID

                // Save the new order to the server
                saveOrder(container, url);
            }
        });

        // Store the instance reference on the container element itself for later destruction
        container._sortableInstance = sortableInstance;
    });
}

