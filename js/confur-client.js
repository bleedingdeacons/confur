/**
 * One answer form per answer page, across tabs.
 *
 * The confirmation email's link opens the page in a new tab, and a group
 * clicking it again would get a second copy of the form. Two copies share
 * the unsaved answers in localStorage and each saves over the other. So a
 * page that finds its form already open elsewhere closes itself, and the
 * tab that has the form asks to be brought to the front.
 *
 * Nothing can stop the mail client opening the tab, and a page can only
 * *ask* to be focused: browsers usually ignore window.focus() from a
 * background tab. So the tab with the form also flashes its title until it
 * has focus again, which shows in the tab strip whatever the browser does.
 *
 * Tabs find each other on a BroadcastChannel named for the page. A tab that
 * shows the form answers "open?" with "open"; a tab still deciding stays
 * quiet, so two tabs loading at the same moment can both show the form,
 * which is no worse than before. Without BroadcastChannel the form shows as
 * it always did.
 */
const confurAnswerTabs = (pageName) => {
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel(`confur-answers:${pageName}`) : null;
    let showing = false;

    // Flash the title until this tab is looked at, then put it back.
    let flashing = null;
    const callAttention = () => {
        window.focus();

        if (document.hasFocus() || flashing) {
            return;
        }

        const title = document.title;
        let on = false;
        flashing = setInterval(() => {
            on = !on;
            document.title = on ? 'Your answers are here' : title;
        }, 1000);

        const stop = () => {
            clearInterval(flashing);
            flashing = null;
            document.title = title;
            window.removeEventListener('focus', stop);
        };
        window.addEventListener('focus', stop);
    };

    if (channel) {
        channel.addEventListener('message', (event) => {
            if (showing && event.data && event.data.type === 'open?') {
                channel.postMessage({ type: 'open' });
                callAttention();
            }
        });
    }

    return {
        // Resolves true when another tab already shows this page's form.
        isOpenElsewhere: () => new Promise((resolve) => {
            if (!channel) {
                resolve(false);
                return;
            }

            const finish = (open) => {
                clearTimeout(timer);
                channel.removeEventListener('message', listen);
                resolve(open);
            };
            const listen = (event) => {
                if (event.data && event.data.type === 'open') {
                    finish(true);
                }
            };
            const timer = setTimeout(() => finish(false), 500);

            channel.addEventListener('message', listen);
            channel.postMessage({ type: 'open?' });
        }),

        // This tab now shows the form, so it answers the tabs opened after it.
        showing: () => {
            showing = true;
        },
    };
};

document.addEventListener('DOMContentLoaded', () => {

    (async () => {

        // Set by AssetService: this page's slug and its REST endpoints.
        const config = window.confurAnswerPage || {};

        const pageName = config.slug || window.location.pathname.split('/').filter(segment => segment).pop();

        const localStorageName = pageName;

        const main = document.getElementById('main-content');

        // Already open in another tab: close this one, and the other comes
        // forward. A tab the email link opened may close itself; one that
        // will not (opened some other way) says where the answers are.
        const tabs = confurAnswerTabs(pageName);

        // Nothing may be typed while the other tabs are asked: the form is
        // rebuilt from its markup just below, which would drop it. Fields
        // already read-only stay so. readOnly reflects to the markup, so it
        // is put back before the rebuild.
        const answerFields = Array.from(main.querySelectorAll('textarea[id^="c"]')).filter(field => !field.readOnly);
        answerFields.forEach(field => { field.readOnly = true; });
        const openElsewhere = await tabs.isOpenElsewhere();
        answerFields.forEach(field => { field.readOnly = false; });

        if (openElsewhere) {
            window.close();

            const notice = document.createElement('div');
            notice.id = 'confur-tab-notice';
            notice.style.cssText = 'max-width: 640px; margin: 40px auto; padding: 24px; border-left: 4px solid #3498db; background: #e8f4fd; border-radius: 4px; color: #2c3e50;';
            notice.textContent = 'These answers are already open in another tab or window. Please carry on there, and close this one.';
            main.replaceChildren(notice);
            return;
        }

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

        // Create progress indicator element
        const progressIndicator = document.createElement('div');
        progressIndicator.id = 'progress-indicator';
        progressIndicator.style.cssText = `
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        `;
        progressIndicator.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                <div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
                <div id="progress-text" style="font-size: 16px; color: #333;">Loading...</div>
            </div>
        `;
        document.body.appendChild(progressIndicator);

        // Add CSS animation for spinner
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);

        // Helper functions to show/hide progress
        const showProgress = (message = 'Loading...') => {
            const progressText = document.getElementById('progress-text');
            if (progressText) {
                progressText.textContent = message;
            }
            progressIndicator.style.display = 'flex';
        };

        const hideProgress = () => {
            progressIndicator.style.display = 'none';
        };

        // Show progress while fetching initial status
        showProgress('Loading...');

        try {
            const response = await fetch(config.statusUrl);
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
        } finally {
            hideProgress();
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

            document.getElementById('topSaveTime').textContent = updated;
            document.getElementById('topSaveState').textContent = state;

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

        // Save to the REST endpoint: the requested state and every answer
        // field (cN_aM) on the page. A refusal from the server (a cancelled
        // registration, say) rejects with the server's own message.
        const saveAnswers = async (requestedState) => {
            const answers = {};
            for (const [name, value] of new FormData(form).entries()) {
                if (/^c\d+_a\d+$/.test(name)) {
                    answers[name] = value;
                }
            }

            const response = await fetch(config.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ state: requestedState, answers }),
            });

            // An error response may not be JSON; read what we can from it.
            // A success must be: a proxy or login page answering 200 with
            // HTML is not a save, and treating it as one would clear the
            // group's local copy of answers the server never received.
            const body = await response.json().catch(() => null);

            if (!response.ok) {
                const error = new Error('Form submission failed');
                error.serverMessage = body && body.message;
                throw error;
            }

            if (!body || typeof body.state !== 'string') {
                throw new Error('The server did not confirm the save');
            }

            return body;
        };

        const handleSubmit = (event) => {
            showProgress('Saving...');
            const button = event.target;
            saveAnswers(button.value)
                .then(responseData => {

                    console.log('Answers submitted successfully:', responseData);

                    // Reset dirty flags and update hidden textareas
                    Object.values(textareas).forEach(group => {
                        group.textareas.forEach(({ visible, hidden }) => {
                            hidden.value = visible.value; // Update hidden textarea
                        });
                    });

                    state = responseData.state;
                    updated = responseData.updated;

                    // The server keeps an incomplete set as a draft even when
                    // Complete was asked for; say why rather than silently
                    // showing Draft.
                    if (button.value !== state && responseData.message) {
                        alert(responseData.message);
                    }

                    // Reset dirty state
                    isDirty = false;
                    updateDirtyIndicators();
                    updateFinishButtons();
                    updateState();

                    // Clear localStorage
                    localStorage.removeItem(localStorageName);

                    // Hide progress indicator
                    hideProgress();

                    // Update save time indicators
                    //document.getElementById('topSaveTime').innerHTML = responseData.data.updated;
                    //document.getElementById('bottomSaveTime').innerHTML = savedAt;

                })
                .catch(error => {
                    hideProgress();
                    console.error('Error submitting form:', error);
                    alert(error.serverMessage || 'Failed to Save Answers. Please try again! Contact Support');
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

        // Answer the tabs opened after this one (see confurAnswerTabs).
        tabs.showing();
    })();
});