document.addEventListener('DOMContentLoaded', () => {

    (async () => {

        const url = window.location.pathname;

        const pageName = url.split('/').filter(segment => segment).pop();

        const localStorageName = pageName;

        const main = document.getElementById('main-content');

        main.innerHTML =
            '<form id="answer-form">' + main.innerHTML + '</form>';

        const form = document.getElementById('answer-form');


        const topSubmitButton = document.getElementById('topSubmit');
        const topFinish = document.getElementById('topFinish');
        const topDirtyIndicator = document.getElementById('topDirty');
        const topSaveTime = document.getElementById('topSaveTime');
        const topSaveState = document.getElementById('topSaveState');
        // const bottomSubmitButton = document.getElementById('bottomSubmit');
        //const bottomDirtyIndicator = document.getElementById('bottomDirty');
        //const bottomFinish = document.getElementById('bottomFinish');

        let isDirty = false; // Variable to hold the dirty state
        let state = "Draft";
        let updated ="N/A";

        try {
            const request = '/wp-json/answer/v1/status/' + pageName;
            const response = await fetch(request);
            if (response.ok) {
                const data = await response.json();
                console.log(data);
                if (data) {
                    state = data.state;
                    updated = data.updated;
                    console.log(`Fetched state from server: ${state} ${updated}`);
                }
            } else {
                console.warn('Could not fetch initial state. Defaulting to Draft.');
            }
        } catch (error) {
            console.warn('Fetch error, defaulting to Draft:', error);
        }


        // Collect all textarea pairs and group them by their "c<number>" prefix
        const textareas = Array.from(document.querySelectorAll('textarea[id^="c"]')).reduce((groups, visible) => {
            const groupMatch = visible.id.match(/^c(\d+)_/);
            if (groupMatch) {
                const groupId = groupMatch[1];
                const hidden = document.getElementById(`e_${visible.id}`);
                if (!groups[groupId]) {
                    groups[groupId] = { statusElement: document.getElementById(`s_c${groupId}`), textareas: [] };
                }
                groups[groupId].textareas.push({ visible, hidden });
            }
            return groups;
        }, {});

        // Populate form with localStorage data (if available)
        const savedValues = JSON.parse(localStorage.getItem(localStorageName)) || {};
        Object.values(textareas).forEach(group => {
            group.textareas.forEach(({ visible, hidden }) => {
                const savedValue = savedValues[visible.id] || null;
                if (savedValue !== null) {
                    visible.value = savedValue;
                    if (visible.value !== hidden.value) {
                        isDirty = true; // Mark form as dirty if recovered values differ
                    }
                }
            });
        });

        const updateState = () => {

            document.getElementById('topSaveTime').innerHTML = updated;
            document.getElementById('topSaveState').innerHTML = state;

        }

        // Update group statuses
        const updateGroupStatuses = () => {
            Object.values(textareas).forEach(group => {
                const total = group.textareas.length;
                const completed = group.textareas.filter(({ visible }) => visible.value.trim() !== '').length;
                const percentage = Math.floor((completed / total) * 100);

                if (percentage === 100) {
                    group.statusElement.textContent = 'Completed';
                } else if (percentage > 0) {
                    group.statusElement.textContent = `${percentage}% Complete`;

                } else {
                    group.statusElement.textContent = 'Not Started';

                }
            });
        };

        // Update submit button and dirty indicators
        const updateDirtyIndicators = () => {
            if (state === 'Cancelled') {
                topDirty.style.display = 'none';
                //bottomDirty.style.display = 'none';
                //bottomSubmit.disabled = true;
                topSubmit.disabled = true;
            } else {
                topDirty.style.display = isDirty ? 'block' : 'none';
                //bottomDirty.style.display = isDirty ? 'block' : 'none';
                //bottomSubmit.disabled = !isDirty;
                topSubmit.disabled = !isDirty;
            }
        };

        // Enable/disable finish buttons
        const updateFinishButtons = () => {
            const allCompleted = Object.values(textareas).every((group) =>
                group.textareas.every(({ visible }) => visible.value.trim() !== '')
            );

            if (state === 'Cancelled') {
                topFinish.disabled = true;
                //bottomFinish.disabled = true;
                topSubmit.disabled = true;
                //bottomSubmit.disabled = true;
                Object.values(textareas).forEach((group) => {
                    group.textareas.forEach(({ visible }) => {
                        visible.readOnly = true;
                    });
                });
            } else if (state === 'Complete' && isDirty) {
                topFinish.disabled = true;
                //bottomFinish.disabled = true;
                topSubmit.disabled = false;
                //bottomSubmit.disabled = false;
            } else if (state === 'Complete' && allCompleted) {
                topFinish.disabled = true;
                //bottomFinish.disabled = true;
            } else if (allCompleted) {
                topFinish.disabled = false;
                //bottomFinish.disabled = false;
            } else {
                topFinish.disabled = true;
                //bottomFinish.disabled = true;
            }
        };

        // Check for dirty state and update group statuses
        Object.values(textareas).forEach(group => {
            group.textareas.forEach(({ visible, hidden }) => {
                visible.addEventListener('input', () => {
                    isDirty = Object.values(textareas).some(group =>
                        group.textareas.some(({ visible, hidden }) => visible.value !== hidden.value)
                    );
                    updateDirtyIndicators();
                    updateGroupStatuses();
                    updateFinishButtons();
                    updateState();

                    // Save changes to localStorage in real-time
                    const formData = {};
                    Object.values(textareas).forEach(group => {
                        group.textareas.forEach(({ visible }) => {
                            formData[visible.id] = visible.value;

                        });
                    });
                    localStorage.setItem(localStorageName, JSON.stringify(formData));
                });
            });
        });

        // Handle form submission with promises
        const postFormData = (formData) => {
            return new Promise((resolve, reject) => {
                fetch('/wp-admin/admin-post.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => {
                        if (response.ok) {
                            resolve(response.json());
                        } else {
                            reject(new Error('Form submission failed'));
                        }
                    })
                    .catch(error => reject(error));
            });
        };

        const handleSubmit = (event) => {
            const button = event.target;
            const formData = new FormData(form);
            formData.append(button.name, button.value);
            postFormData(formData)
                .then(responseData => {

                    console.log('Answers submitted successfully:', responseData);

                    // Reset dirty flags and update hidden textareas
                    Object.values(textareas).forEach(group => {
                        group.textareas.forEach(({ visible, hidden }) => {
                            hidden.value = visible.value; // Update hidden textarea
                        });
                    });

                    state = responseData.data.state;
                    updated = responseData.data.updated;

                    // Reset dirty state
                    isDirty = false;
                    updateDirtyIndicators();
                    updateFinishButtons();
                    updateState();

                    // Clear localStorage
                    localStorage.removeItem(localStorageName);

                    // Update save time indicators
                    //document.getElementById('topSaveTime').innerHTML = responseData.data.updated;
                    //document.getElementById('bottomSaveTime').innerHTML = savedAt;

                })
                .catch(error => {
                    console.error('Error submitting form:', error);
                    alert('Failed to Save Answers. Please try again! Contact Support support@aa-bristol.org');
                });
        };


        topSubmitButton.addEventListener('click', handleSubmit);
        topFinish.addEventListener('click', handleSubmit);
        //bottomSubmitButton.addEventListener('click', handleSubmit);
        //bottomFinish.addEventListener('click', handleSubmit);

        // Save current values to localStorage when the page is about to unload
        window.addEventListener('beforeunload', (event) => {
            if (isDirty) {
                const formData = {};
                Object.values(textareas).forEach(group => {
                    group.textareas.forEach(({ visible }) => {
                        formData[visible.id] = visible.value;
                    });
                });
                localStorage.setItem(localStorageName, JSON.stringify(formData));

                event.preventDefault();
                event.returnValue = '';
            }
        });

        // Initialize submit button and dirty indicators
        updateDirtyIndicators();
        updateGroupStatuses();
        updateFinishButtons();
        updateState();
    })();
});
