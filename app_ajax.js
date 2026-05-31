/**
 * ============================================================
 *  AnyBuddy — Frontend AJAX Integration Layer
 *  File   : app_ajax.js
 *  Stack  : Vanilla JS ES2022 + Fetch API (no jQuery required)
 *  Covers :
 *    1. User Registration  (signup.html  → ajax_register.php)
 *    2. User Login         (login.html   → ajax_login.php)
 *    3. Become a Buddy     (become-buddy.html → ajax_become_buddy.php)
 *    4. Marketplace Search (marketplace.html  → ajax_marketplace.php)
 *       — hero search bar
 *       — sidebar filter checkboxes / rate inputs / selects
 *       — sort dropdown
 *       — pagination
 * ============================================================
 */

'use strict';

/* ─────────────────────────────────────────────────────────────
   GLOBAL FETCH INTERCEPTOR (AUTH SYNC)
   ───────────────────────────────────────────────────────────── */
const originalFetch = window.fetch;
window.fetch = async function(...args) {
    const response = await originalFetch(...args);
    
    if (response.status === 401 || response.status === 403) {
        // Clear local auth state if backend rejects the session
        localStorage.removeItem('ab_user');
        
        // Skip redirect if already on auth pages
        const path = window.location.pathname;
        if (!path.includes('login.html') && !path.includes('signup.html')) {
            // We use standard alert or custom toast if available (toast isn't defined yet, but we'll use a direct redirect or store intent)
            localStorage.setItem('ab_trigger_login', 'true');
            window.location.href = 'login.html?redirect=' + encodeURIComponent(window.location.href);
        }
    }
    
    return response;
};

/* ─────────────────────────────────────────────────────────────
   SECTION 0 ── Shared utilities
   ───────────────────────────────────────────────────────────── */

/**
 * Display an inline error message below a form field.
 * @param {HTMLElement} field   - The input / select / textarea element
 * @param {string}      message - Error text to display
 */
function showFieldError(field, message) {
    clearFieldError(field);
    field.classList.add('is-invalid');

    const errorEl = document.createElement('span');
    errorEl.className   = 'field-error-msg';
    errorEl.textContent = message;
    errorEl.setAttribute('role', 'alert');
    field.insertAdjacentElement('afterend', errorEl);
}

/**
 * Clear a field's error state.
 * @param {HTMLElement} field
 */
function clearFieldError(field) {
    field.classList.remove('is-invalid');
    const existing = field.nextElementSibling;
    if (existing && existing.classList.contains('field-error-msg')) {
        existing.remove();
    }
}

/**
 * Apply a map of { fieldName: errorMessage } onto a form.
 * @param {HTMLFormElement} form
 * @param {Object}          errors
 */
function applyFieldErrors(form, errors) {
    Object.entries(errors).forEach(([name, msg]) => {
        // Field names may use dashes in HTML but underscores from the server
        const field = form.querySelector(`[name="${name}"]`)
                   || form.querySelector(`[name="${name.replace(/_/g, '-')}"]`);
        if (field) showFieldError(field, msg);
    });
}

/**
 * Clear ALL error indicators inside a form.
 * @param {HTMLFormElement} form
 */
function clearAllErrors(form) {
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    form.querySelectorAll('.field-error-msg').forEach(el => el.remove());
}

/**
 * Show a floating toast notification.
 * @param {string}  message
 * @param {'success'|'error'|'info'} type
 * @param {number}  duration  ms before auto-dismiss (0 = never)
 */
function showToast(message, type = 'info', duration = 4000) {
    let container = document.getElementById('ab-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'ab-toast-container';
        container.setAttribute('aria-live', 'polite');
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className  = `ab-toast ab-toast--${type}`;
    toast.textContent = message;
    toast.setAttribute('role', 'status');
    container.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => toast.classList.add('ab-toast--visible'));

    if (duration > 0) {
        setTimeout(() => {
            toast.classList.remove('ab-toast--visible');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, duration);
    }
}

/**
 * Set a submit button into loading state.
 * @param {HTMLButtonElement} btn
 * @param {boolean}           loading
 */
function setButtonLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
        btn.dataset.origText = btn.textContent;
        btn.disabled         = true;
        btn.textContent      = 'Please wait…';
    } else {
        btn.disabled    = false;
        btn.textContent = btn.dataset.origText ?? btn.textContent;
    }
}

/**
 * Store lightweight auth state in localStorage so pages can read it.
 * In a production app use HttpOnly cookies and PHP sessions instead.
 * @param {Object|null} userData
 */
function persistAuthState(userData) {
    if (userData) {
        localStorage.setItem('ab_user', JSON.stringify(userData));
    } else {
        localStorage.removeItem('ab_user');
    }
}

/** Read the current user from localStorage (null if not logged in). */
function getCurrentUser() {
    try {
        return JSON.parse(localStorage.getItem('ab_user') || 'null');
    } catch {
        return null;
    }
}

/** Get favourites list for the logged-in user or guest */
function getFavourites() {
    const user = getCurrentUser();
    const key = user ? `ab_favourites_${user.id}` : 'ab_favourites_guest';
    try {
        return JSON.parse(localStorage.getItem(key) || '[]').map(item => Number(item));
    } catch {
        return [];
    }
}

/** Toggle a buddy as favourite */
function toggleFavourite(buddyId) {
    const user = getCurrentUser();
    if (!user) {
        showToast('Please log in to add buddies to your favourites!', 'warning');
        openAuthModal('login');
        return null;
    }
    const key = `ab_favourites_${user.id}`;
    let favs = getFavourites();
    const buddyIdNum = Number(buddyId);
    const idx = favs.indexOf(buddyIdNum);
    let isFav = false;
    if (idx > -1) {
        favs.splice(idx, 1);
    } else {
        favs.push(buddyIdNum);
        isFav = true;
    }
    localStorage.setItem(key, JSON.stringify(favs));
    return isFav;
}

/**
 * Escape HTML characters to prevent XSS and formatting issues.
 * @param {string} str 
 * @returns {string}
 */
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


/* ─────────────────────────────────────────────────────────────
   SECTION 1 ── Registration (signup.html & Modal)
   ───────────────────────────────────────────────────────────── */

// Generic Signup Form Handler
async function handleSignupSubmit(form, e) {
    e.preventDefault();
    clearAllErrors(form);

    const btn = form.querySelector('[type="submit"]');
    setButtonLoading(btn, true);

    const payload = {
        first_name:       form.querySelector('[name="first-name"]').value.trim(),
        last_name:        form.querySelector('[name="last-name"]').value.trim(),
        email:            form.querySelector('[name="email"]').value.trim(),
        pronouns:         form.querySelector('[name="pronouns"]')?.value.trim() ?? '',
        password:         form.querySelector('[name="password"]').value,
        confirm_password: form.querySelector('[name="confirm-password"]').value,
    };

    try {
        const response = await fetch('ajax_register.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });

        const data = await response.json();

            if (data.status === 'success') {
                showToast(data.message, 'success', 5000);
                form.reset();
                localStorage.setItem('just_signed_up', 'true');
                
                // Check if this is the modal or the standalone page
                const modal = document.getElementById('authOverlay');
                if (modal && modal.classList.contains('active')) {
                    // Switch to login tab in modal
                    switchAuthTab('login');
                } else {
                    // Trigger Portal Transition
                    const urlParams = new URLSearchParams(window.location.search);
                    const clientRedirect = urlParams.get('redirect');
                    const finalRedirect = clientRedirect ? `login.html?redirect=${encodeURIComponent(clientRedirect)}` : (data.redirect ?? 'login.html');
                    triggerPortalTransition('Welcome to AnyBuddy! Let\'s get you set up. ✨', finalRedirect);
                }
            } else {
            showToast(data.message, 'error');
            if (data.errors) applyFieldErrors(form, data.errors);
        }
    } catch (err) {
        showToast('Network error — please check your connection and try again.', 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}


/* ─────────────────────────────────────────────────────────────
   SECTION 2 ── Login (login.html & Modal)
   ───────────────────────────────────────────────────────────── */

// Generic Login Form Handler
async function handleLoginSubmit(form, e) {
    e.preventDefault();
    clearAllErrors(form);

    const btn = form.querySelector('[type="submit"]');
    setButtonLoading(btn, true);

    const payload = {
        email:    form.querySelector('[name="email"]').value.trim(),
        password: form.querySelector('[name="password"]').value,
    };

    try {
        const response = await fetch('ajax_login.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });

        const data = await response.json();

            if (data.status === 'success') {
                persistAuthState(data.user);
                showToast(data.message, 'success', 3000);
                
                // Check if this is the modal or the standalone page
                const modal = document.getElementById('authOverlay');
                if (modal && modal.classList.contains('active')) {
                    closeAuthModal();
                    updateNavForAuthState();
                    updateHomepageWelcomeMessage();
                }
                
                const name = data.user ? data.user.first_name : 'Buddy';
                const urlParams = new URLSearchParams(window.location.search);
                const clientRedirect = urlParams.get('redirect');
                const postLoginRedirect = localStorage.getItem('ab_post_login_redirect');
                let finalRedirect = clientRedirect || postLoginRedirect || data.redirect || 'homepage.html';
                if (localStorage.getItem('just_signed_up') === 'true') {
                    finalRedirect = 'welcome.html';
                }
                localStorage.removeItem('ab_post_login_redirect');
                triggerPortalTransition(`Welcome back, ${name}! Ready to explore? 👋`, finalRedirect);
            } else {
            showToast(data.message, 'error');
            if (data.errors) applyFieldErrors(form, data.errors);
        }
    } catch (err) {
        showToast('Network error — please check your connection and try again.', 'error');
    } finally {
        setButtonLoading(btn, false);
    }
}

// Global delegated form handlers
document.addEventListener('submit', function (e) {
    const signupForm = e.target.closest('.signup-form');
    const loginForm = e.target.closest('.login-form');
    
    if (signupForm) {
        handleSignupSubmit(signupForm, e);
    } else if (loginForm) {
        handleLoginSubmit(loginForm, e);
    }
});

document.addEventListener('input', function (e) {
    if (e.target.tagName === 'INPUT') {
        clearFieldError(e.target);
    }
});

/* ─────────────────────────────────────────────────────────────
   SECTION 2B ── Glassmorphic Auth Modal & Scroll Animations
   ───────────────────────────────────────────────────────────── */

function updateHomepageWelcomeMessage() {
    const user = getCurrentUser();
    const msgEl = document.getElementById('welcome-message');
    if (msgEl) {
        if (user) {
            msgEl.textContent = `Welcome back, ${user.first_name}!`;
        } else {
            msgEl.textContent = "WELCOME TO ANYBUDDY";
        }
    }
}

function openAuthModal(mode = 'login') {
    let overlay = document.getElementById('authOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'authOverlay';
        overlay.className = 'auth-overlay';
        overlay.innerHTML = `
            <div class="auth-modal-card">
                <button type="button" class="auth-close" id="authCloseBtn" aria-label="Close modal">&times;</button>
                <div class="auth-tabs">
                    <button type="button" class="auth-tab-btn" id="tabBtnLogin">Login</button>
                    <button type="button" class="auth-tab-btn" id="tabBtnSignup">Sign Up</button>
                    <div class="auth-tab-slider" id="authTabSlider"></div>
                </div>
                
                <!-- Login Panel -->
                <div class="auth-panel" id="authLoginPanel">
                    <form class="login-form">
                        <div class="form-group" style="margin-bottom: 1.25rem; text-align: left;">
                            <label for="modal-login-email" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Email Address</label>
                            <input type="email" id="modal-login-email" name="email" placeholder="email@example.com" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <div class="form-group" style="margin-bottom: 1.5rem; text-align: left;">
                            <label for="modal-login-password" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Password</label>
                            <input type="password" id="modal-login-password" name="password" placeholder="••••••••" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <button type="submit" class="bab-btn" style="width:100%; padding:0.85rem; font-weight:700; border:none; cursor:pointer; font-family:inherit; border-radius:20px;">Login</button>
                    </form>
                </div>
                
                <!-- Signup Panel -->
                <div class="auth-panel" id="authSignupPanel">
                    <form class="signup-form">
                        <div class="name-row" style="display:flex; gap:1rem; margin-bottom: 1rem; text-align: left;">
                            <div class="form-group" style="flex:1;">
                                <label for="modal-signup-first-name" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">First Name</label>
                                <input type="text" id="modal-signup-first-name" name="first-name" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="modal-signup-last-name" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Last Name</label>
                                <input type="text" id="modal-signup-last-name" name="last-name" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem; text-align: left;">
                            <label for="modal-signup-pronouns" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Pronouns</label>
                            <input type="text" id="modal-signup-pronouns" name="pronouns" placeholder="e.g. she/her, he/him, they/them" style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem; text-align: left;">
                            <label for="modal-signup-email" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Email Address</label>
                            <input type="email" id="modal-signup-email" name="email" placeholder="email@example.com" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem; text-align: left;">
                            <label for="modal-signup-password" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Password</label>
                            <input type="password" id="modal-signup-password" name="password" placeholder="••••••••" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <div class="form-group" style="margin-bottom: 1.5rem; text-align: left;">
                            <label for="modal-signup-confirm" style="display:block; margin-bottom:0.5rem; font-weight:700; font-size:0.9rem;">Confirm Password</label>
                            <input type="password" id="modal-signup-confirm" name="confirm-password" placeholder="••••••••" required style="width:100%; padding:0.75rem; border-radius:8px; border:1px solid var(--border-modal); background:var(--bg-modal-input); color:var(--text-modal); font-family:inherit;">
                        </div>
                        <button type="submit" class="bab-btn" style="width:100%; padding:0.85rem; font-weight:700; border:none; cursor:pointer; font-family:inherit; border-radius:20px;">Sign Up</button>
                    </form>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        // Bind events
        document.getElementById('authCloseBtn').addEventListener('click', closeAuthModal);
        document.getElementById('tabBtnLogin').addEventListener('click', () => switchAuthTab('login'));
        document.getElementById('tabBtnSignup').addEventListener('click', () => switchAuthTab('signup'));
        
        // Close on background click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeAuthModal();
            }
        });
    }

    // Switch to initial tab
    switchAuthTab(mode);

    // Show modal
    overlay.style.display = 'flex';
    // Trigger transition
    requestAnimationFrame(() => {
        overlay.classList.add('active');
    });

    // Handle ESC key to close
    document.addEventListener('keydown', handleEscClose);
}

function closeAuthModal() {
    const overlay = document.getElementById('authOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.addEventListener('transitionend', function handler() {
            overlay.style.display = 'none';
            overlay.removeEventListener('transitionend', handler);
        }, { once: true });
    }
    document.removeEventListener('keydown', handleEscClose);
}

function handleEscClose(e) {
    if (e.key === 'Escape') {
        closeAuthModal();
    }
}

function switchAuthTab(tab) {
    const loginBtn = document.getElementById('tabBtnLogin');
    const signupBtn = document.getElementById('tabBtnSignup');
    const loginPanel = document.getElementById('authLoginPanel');
    const signupPanel = document.getElementById('authSignupPanel');
    const slider = document.getElementById('authTabSlider');

    if (!loginBtn || !signupBtn || !loginPanel || !signupPanel || !slider) return;

    if (tab === 'login') {
        loginBtn.classList.add('active');
        signupBtn.classList.remove('active');
        loginPanel.classList.add('active');
        signupPanel.classList.remove('active');
        slider.style.transform = 'translateX(0)';
    } else {
        loginBtn.classList.remove('active');
        signupBtn.classList.add('active');
        loginPanel.classList.remove('active');
        signupPanel.classList.add('active');
        slider.style.transform = 'translateX(100%)';
    }
}


// Setup Scroll Reveal animations and welcome banner updates on page load
document.addEventListener('DOMContentLoaded', () => {
    // Scroll reveal observer initialization
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            } else {
                entry.target.classList.remove('visible');
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });
    
    document.querySelectorAll('.scroll-reveal').forEach(el => revealObserver.observe(el));

    // Dynamic welcome message check
    updateHomepageWelcomeMessage();

    // Check if we need to auto-open login modal after a guest redirect
    if (localStorage.getItem('ab_trigger_login') === 'true') {
        localStorage.removeItem('ab_trigger_login');
        setTimeout(() => {
            showToast('Please log in or sign up to continue.', 'info');
            openAuthModal('login');
        }, 300);
    }
});


/* ─────────────────────────────────────────────────────────────
   SECTION 3 ── Become a Buddy (become-buddy.html)
   ───────────────────────────────────────────────────────────── */

(function initBecomeBuddy() {
    const form = document.querySelector('.become-buddy-form');
    if (!form) return;

    // Pre-fill user_id from localStorage auth state
    const user = getCurrentUser();
    if (user && user.buddy_profile_id) {
        showToast('You are already registered as a Buddy. Redirecting to homepage...', 'info', 5000);
        setTimeout(() => { window.location.href = 'homepage.html'; }, 2000);
        return;
    }
    let userId  = user?.id ?? 0;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearAllErrors(form);

        // Re-read user in case they logged in after page load
        const freshUser = getCurrentUser();
        userId = freshUser?.id ?? 0;

        if (!userId) {
            showToast('You must be logged in to register as a Buddy. Redirecting to login…', 'error', 5000);
            setTimeout(() => { window.location.href = 'login.html'; }, 2000);
            return;
        }

        const btn = form.querySelector('[type="submit"]');
        setButtonLoading(btn, true);

        const formData = new FormData();
        formData.append('user_id', userId);
        formData.append('display-name', form.querySelector('#display-name').value.trim());
        formData.append('title', form.querySelector('#title').value.trim());
        formData.append('category', form.querySelector('#category').value);
        formData.append('bio', form.querySelector('#bio').value.trim());
        formData.append('rate', form.querySelector('#rate').value);
        formData.append('location', form.querySelector('#location').value.trim());
        formData.append('availability', form.querySelector('#availability').value.trim());
        
        const tierInput = form.querySelector('#verification-type');
        if (tierInput) {
            formData.append('verification_type', tierInput.value);
        }

        const avatarInput = form.querySelector('#avatar');
        if (avatarInput && avatarInput.files.length > 0) {
            formData.append('avatar', avatarInput.files[0]);
        }

        const idPhotoInput = form.querySelector('#id-photo');
        if (idPhotoInput && idPhotoInput.files.length > 0) {
            formData.append('id_photo', idPhotoInput.files[0]);
        }

        try {
            const response = await fetch('ajax_become_buddy.php', {
                method:  'POST',
                body:    formData
            });

            const data = await response.json();

            if (data.status === 'success') {
                showToast(data.message, 'success', 5000);
                // Update localStorage to reflect buddy status
                if (freshUser) {
                    freshUser.is_buddy         = true;
                    freshUser.buddy_profile_id = data.profile?.id ?? null;
                    persistAuthState(freshUser);
                }
                form.reset();
                setTimeout(() => {
                    window.location.href = data.redirect ?? 'marketplace.html';
                }, 1800);
            } else {
                showToast(data.message, 'error');
                if (data.errors) applyFieldErrors(form, data.errors);
            }
        } catch (err) {
            showToast('Network error — please check your connection and try again.', 'error');
        } finally {
            setButtonLoading(btn, false);
        }
    });

    // Live clear-on-input
    form.querySelectorAll('input, textarea, select').forEach(el => {
        el.addEventListener('input',  () => clearFieldError(el));
        el.addEventListener('change', () => clearFieldError(el));
    });
})();


/* ─────────────────────────────────────────────────────────────
   SECTION 4 ── Marketplace (marketplace.html)
   ───────────────────────────────────────────────────────────── */

(function initMarketplace() {
    /* ── Guard: only run on marketplace page ── */
    const buddyGrid     = document.querySelector('.buddy-grid');
    if (!buddyGrid) return;

    /* ── Element references ── */
    const heroForm      = document.querySelector('.search-bar');
    const heroQuery     = heroForm?.querySelector('[name="query"]');
    const heroLocation  = heroForm?.querySelector('[name="location"]');

    const resultsCount  = document.querySelector('.results-count');
    const resetLink     = document.querySelector('.reset-link');
    const paginationNav = document.querySelector('.pagination');

    // Redesigned Filter Controls
    const categoryPills   = document.querySelectorAll('.category-pill');
    const minRateInput    = document.querySelector('.rate-slider-min');
    const maxRateInput    = document.querySelector('.rate-slider-max');
    const starBtns        = document.querySelectorAll('.star-btn');
    const ratingLabel     = document.querySelector('.star-rating-label');
    const locationSelect  = document.querySelector('[name="location-filter"]');
    const sortBtns        = document.querySelectorAll('.sort-seg-btn');

    /* ── State ── */
    let currentPage = 1;
    const perPage   = 9;
    let debounceTimer = null;
    let selectedMinRating = 0;
    let selectedSort = 'recommended';

    /* ── Build query-string from current UI state ── */
    function buildQueryParams(page = 1) {
        const params = new URLSearchParams();

        const q = heroQuery?.value.trim() ?? '';
        if (q)   params.set('query', q);

        // Location — prefer the hero bar value; fall back to sidebar select
        const heroLoc = heroLocation?.value.trim() ?? '';
        if (heroLoc) {
            params.set('location', heroLoc);
        } else if (locationSelect?.value) {
            params.set('location', locationSelect.value);
        }

        // Category pills
        categoryPills.forEach(pill => {
            if (pill.classList.contains('active')) {
                params.append('category[]', pill.dataset.category);
            }
        });

        // Rate range slider
        if (minRateInput && maxRateInput) {
            const minR = minRateInput.value.trim();
            const maxR = maxRateInput.value.trim();
            if (minR && !isNaN(minR)) params.set('min-rate', minR);
            if (maxR && !isNaN(maxR)) params.set('max-rate', maxR);
        }

        // Min rating
        if (selectedMinRating > 0) {
            params.set('min-rating', String(selectedMinRating));
        }

        // Sort segmented buttons
        if (selectedSort) {
            params.set('sort', selectedSort);
        }

        // Favourites view
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'favourites') {
            const favs = getFavourites();
            if (favs.length > 0) {
                params.set('ids', favs.join(','));
            } else {
                params.set('ids', '0'); // Non-matching ID to return empty results
            }
        }

        // Pagination
        params.set('page',     String(page));
        params.set('per_page', String(perPage));

        return params;
    }

    /* ── Render a single buddy card ── */
    function renderBuddyCard(buddy, index = 0) {
        const isVerified = (buddy.is_verified || buddy.verification_status === 'verified');
        const verifiedBadge = isVerified
            ? `<span class="verified-badge" title="Verified">✓</span>`
            : '';
        const cardClass = isVerified ? 'buddy-card animate-card verified-glow' : 'buddy-card animate-card';

        // Languages split to tags
        const languagesList = buddy.languages
            ? buddy.languages.split(',').map(l => l.trim()).filter(Boolean)
            : [];
        const languagesHtml = languagesList.map(lang => `<span class="buddy-lang-tag">${lang}</span>`).join('');

        // Service mode class
        const modeClass = `buddy-mode-badge--${(buddy.service_mode || 'Flexible').toLowerCase()}`;

        const favs = getFavourites();
        const isFav = favs.includes(buddy.id);
        const favChar = isFav ? '♥' : '♡';
        const favClass = isFav ? 'favorite-btn is-favorite' : 'favorite-btn';

        const isOnline = (buddy.id % 3 === 0);
        const onlineBadgeHtml = isOnline 
            ? `<div class="online-indicator-badge"><span class="online-dot"></span>Online</div>`
            : '';

        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const fallbackAvatar = isDark ? 'images/user-dark.png' : 'images/user-light.png';
        const avatarSrc = (buddy.avatar_url && buddy.avatar_url.trim() !== '' && buddy.avatar_url !== 'images/user-light.png')
            ? buddy.avatar_url
            : fallbackAvatar;

        return `
        <article class="${cardClass}" data-buddy-id="${buddy.id}" style="--index: ${index}" data-gallery='${JSON.stringify(buddy.gallery || [])}'>
            <div class="buddy-image-wrap">
                ${onlineBadgeHtml}
                <img src="${avatarSrc}"
                     alt="${buddy.display_name}"
                     loading="lazy"
                     onerror="this.src='${fallbackAvatar}'">
                <button type="button" class="${favClass}" aria-label="${isFav ? 'Remove from favorites' : 'Add to favorites'}">${favChar}</button>
                <span class="buddy-mode-badge ${modeClass}">${buddy.service_mode}</span>
            </div>
            <div class="buddy-card-body">
                <h3 class="buddy-name">${buddy.display_name} ${verifiedBadge}</h3>
                ${buddy.tagline ? `<p class="buddy-tagline" style="font-style: italic; font-weight: 600; color: var(--accent); margin-bottom: 0.15rem;">"${buddy.tagline}"</p>` : ''}
                <p class="buddy-title-text" style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.25rem 0; line-height: 1.3;">${buddy.professional_title}</p>
                <p class="buddy-category-label">${buddy.category_label}</p>
                
                <div class="buddy-details-row">
                    <span class="buddy-detail-chip">💼 ${buddy.total_gigs} Gig${buddy.total_gigs !== 1 ? 's' : ''}</span>
                    <span class="buddy-detail-chip">⚡ ${buddy.response_time}</span>
                </div>

                <div class="buddy-hover-bio" style="max-height: 0; opacity: 0; overflow: hidden; transition: max-height 0.3s ease, opacity 0.3s ease; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.4; margin: 0.25rem 0 0;">
                    ${buddy.full_bio}
                </div>

                <div class="buddy-languages">
                    ${languagesHtml}
                </div>

                <div class="buddy-meta">
                    <span class="buddy-rating">★ ${buddy.rating.toFixed(1)}
                        <span class="review-count">(${buddy.review_count})</span>
                    </span>
                    <span class="buddy-price">${buddy.hourly_rate_fmt}/hr</span>
                </div>
                <p class="buddy-location">📍 ${buddy.location}</p>
                <a href="${buddy.profile_url}" class="view-profile-btn">View Profile</a>
            </div>

            <!-- Detailed Hover Bio Overlay -->
            <div class="buddy-hover-bio-overlay">
                <div class="buddy-preview-title">
                    ${buddy.display_name} ${isOnline ? '<span style="color:#10b981; font-size:0.75rem; display:inline-flex; align-items:center; gap:0.2rem;"><span class="online-dot" style="display:inline-block; margin-top:1px;"></span>Online</span>' : ''}
                </div>
                <p class="buddy-title-text" style="font-size:0.8rem; color:var(--accent); font-weight:600; margin:0 0 0.5rem 0;">${buddy.professional_title}</p>
                <div class="buddy-preview-text">
                    ${buddy.full_bio || buddy.tagline || 'No additional bio details provided.'}
                </div>
                <div class="buddy-preview-footer">
                    <div class="buddy-preview-meta">
                        <span>⭐ ${buddy.rating.toFixed(1)} (${buddy.review_count} reviews)</span>
                        <span>📍 ${buddy.location}</span>
                    </div>
                    <div class="buddy-preview-meta" style="margin-bottom: 0.5rem;">
                        <span>💼 ${buddy.total_gigs} Gigs completed</span>
                        <span style="color:var(--text-primary); font-weight:700;">${buddy.hourly_rate_fmt}/hr</span>
                    </div>
                    <a href="${buddy.profile_url}" class="view-profile-btn" style="width:100%; text-align:center;">View Full Profile</a>
                </div>
            </div>
        </article>`;
    }

    /* ── Render empty-state ── */
    function renderEmpty() {
        return `<div class="buddy-grid-empty" style="grid-column:1/-1;text-align:center;padding:3rem 1rem">
                    <p style="font-size:1.2rem;opacity:.6">No Buddies match your filters.</p>
                    <p style="opacity:.45;margin-top:.5rem">Try adjusting your search criteria or clearing the filters.</p>
                </div>`;
    }

    /* ── Render loading skeleton ── */
    function renderSkeletons(count = perPage) {
        return Array.from({ length: count }, () =>
            `<article class="buddy-card buddy-card--skeleton" aria-hidden="true">
                <div class="buddy-image-wrap buddy-skeleton-img"></div>
                <div class="buddy-card-body">
                    <div class="buddy-skeleton-line" style="width:60%;height:1em;margin-bottom:.5rem"></div>
                    <div class="buddy-skeleton-line" style="width:45%;height:.8em;margin-bottom:.5rem"></div>
                    <div class="buddy-skeleton-line" style="width:80%;height:.75em"></div>
                </div>
            </article>`
        ).join('');
    }

    /* ── Render pagination links ── */
    function renderPagination(current, total) {
        if (!paginationNav || total <= 1) {
            if (paginationNav) paginationNav.innerHTML = '';
            return;
        }

        let html = `<a href="#" class="page-nav" data-page="${Math.max(1, current - 1)}" aria-label="Previous page">‹</a>`;

        const range = buildPageRange(current, total);
        range.forEach(item => {
            if (item === '…') {
                html += `<span class="page-ellipsis">…</span>`;
            } else {
                html += `<a href="#" class="page-num ${item === current ? 'is-active' : ''}"
                            data-page="${item}">${item}</a>`;
            }
        });

        html += `<a href="#" class="page-nav" data-page="${Math.min(total, current + 1)}" aria-label="Next page">›</a>`;
        paginationNav.innerHTML = html;

        // Bind pagination clicks
        paginationNav.querySelectorAll('[data-page]').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const p = parseInt(link.dataset.page, 10);
                if (p !== currentPage) {
                    currentPage = p;
                    fetchAndRender();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }

    /** Build a compact page-range array with ellipsis. */
    function buildPageRange(current, total) {
        const pages = [];
        if (total <= 7) {
            for (let i = 1; i <= total; i++) pages.push(i);
            return pages;
        }
        pages.push(1);
        if (current > 3) pages.push('…');
        for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
            pages.push(i);
        }
        if (current < total - 2) pages.push('…');
        pages.push(total);
        return pages;
    }

    /* ── Main fetch-and-render function ── */
    async function fetchAndRender(page = currentPage) {
        // Show skeleton
        buddyGrid.innerHTML = renderSkeletons(perPage);

        const params = buildQueryParams(page);
        const url    = `ajax_marketplace.php?${params.toString()}`;

        try {
            const response = await fetch(url, {
                method:  'GET',
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`Server responded with status ${response.status}`);
            }

            const data = await response.json();

            if (data.status !== 'success') {
                throw new Error(data.message || 'Unknown server error');
            }

            // Rebuild location options dynamically from database
            if (locationSelect && data.locations) {
                const urlParams = new URLSearchParams(window.location.search);
                const currentVal = locationSelect.value || urlParams.get('location') || '';
                
                locationSelect.innerHTML = '<option value="">All Locations</option>';
                data.locations.forEach(loc => {
                    const opt = document.createElement('option');
                    opt.value = loc;
                    opt.textContent = loc;
                    locationSelect.appendChild(opt);
                });
                
                if (currentVal) {
                    const hasOption = data.locations.includes(currentVal);
                    if (!hasOption) {
                        const opt = document.createElement('option');
                        opt.value = currentVal;
                        opt.textContent = currentVal;
                        locationSelect.appendChild(opt);
                    }
                    locationSelect.value = currentVal;
                }
            }

            // Update results count text
            if (resultsCount) {
                resultsCount.innerHTML = `Showing <strong>${data.total}</strong> Buddy${data.total !== 1 ? 's' : ''} matching your filters`;
            }

            if (data.buddies.length === 0) {
                buddyGrid.innerHTML = renderEmpty();
            } else {
                buddyGrid.innerHTML = data.buddies.map((buddy, index) => renderBuddyCard(buddy, index)).join('');
                attachFavoriteButtons();
                if (window.initTiltEffect) window.initTiltEffect();
            }

            // Render pagination
            renderPagination(data.page, data.total_pages);

        } catch (err) {
            buddyGrid.innerHTML = `<div class="buddy-grid-empty" style="grid-column:1/-1;text-align:center;padding:3rem">
                                       <p style="color:var(--color-error,#e53e3e)">Failed to load results.</p>
                                       <p style="opacity:.5;font-size:.9rem;margin-top:.5rem">${err.message}</p>
                                   </div>`;
            if (resultsCount) resultsCount.innerHTML = '';
        }
    }

    /* ── Favorite button toggle (client-side only until backend added) ── */
    function attachFavoriteButtons() {
        buddyGrid.querySelectorAll('.favorite-btn').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                
                const card = this.closest('.buddy-card');
                if (!card) return;
                
                const buddyId = card.dataset.buddyId;
                if (!buddyId) return;
                
                const isFav = toggleFavourite(buddyId);
                if (isFav !== null) {
                    this.classList.toggle('is-favorite', isFav);
                    this.textContent = isFav ? '♥' : '♡';
                    this.setAttribute('aria-label', isFav ? 'Remove from favorites' : 'Add to favorites');
                }
            });
        });
    }

    /* ── Debounced trigger ── */
    function scheduleRefresh() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            currentPage = 1;
            fetchAndRender(1);
        }, 350);
    }

    /* ── Hero search form submit ── */
    if (heroForm) {
        heroForm.addEventListener('submit', e => {
            e.preventDefault();
            currentPage = 1;
            fetchAndRender(1);
        });
    }

    /* ── Sidebar filter listeners ── */
    categoryPills.forEach(pill => {
        pill.addEventListener('click', () => {
            pill.classList.toggle('active');
            scheduleRefresh();
        });
    });

    const sliderFill = document.querySelector('.rate-slider-fill');
    const minRateLabel = document.querySelector('.rate-label-min');
    const maxRateLabel = document.querySelector('.rate-label-max');

    function updateRateSlider() {
        if (!minRateInput || !maxRateInput) return;
        let minVal = parseInt(minRateInput.value, 10);
        let maxVal = parseInt(maxRateInput.value, 10);

        if (minVal > maxVal) {
            minRateInput.value = maxVal;
            minVal = maxVal;
        }

        if (minRateLabel) minRateLabel.textContent = `₱${minVal.toLocaleString()}`;
        if (maxRateLabel) maxRateLabel.textContent = `₱${maxVal.toLocaleString()}`;

        if (sliderFill) {
            const minPercent = (minVal / minRateInput.max) * 100;
            const maxPercent = (maxVal / maxRateInput.max) * 100;
            sliderFill.style.left = minPercent + '%';
            sliderFill.style.width = (maxPercent - minPercent) + '%';
        }
    }

    if (minRateInput && maxRateInput) {
        minRateInput.addEventListener('input', () => {
            let minVal = parseInt(minRateInput.value, 10);
            let maxVal = parseInt(maxRateInput.value, 10);
            if (minVal > maxVal) {
                minRateInput.value = maxVal;
            }
            updateRateSlider();
            scheduleRefresh();
        });
        maxRateInput.addEventListener('input', () => {
            let minVal = parseInt(minRateInput.value, 10);
            let maxVal = parseInt(maxRateInput.value, 10);
            if (maxVal < minVal) {
                maxRateInput.value = minVal;
            }
            updateRateSlider();
            scheduleRefresh();
        });
        updateRateSlider();
    }

    starBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const rating = parseInt(btn.dataset.rating, 10);
            if (selectedMinRating === rating) {
                selectedMinRating = 0;
            } else {
                selectedMinRating = rating;
            }
            updateStarUI();
            scheduleRefresh();
        });
        btn.addEventListener('mouseenter', () => {
            const rating = parseInt(btn.dataset.rating, 10);
            highlightStars(rating, true);
        });
        btn.addEventListener('mouseleave', () => {
            highlightStars(selectedMinRating, false);
        });
    });

    function updateStarUI() {
        highlightStars(selectedMinRating, false);
        if (ratingLabel) {
            if (selectedMinRating > 0) {
                ratingLabel.textContent = `${selectedMinRating} Star${selectedMinRating > 1 ? 's' : ''} & Up`;
            } else {
                ratingLabel.textContent = 'All Ratings';
            }
        }
    }

    function highlightStars(rating, isHover = false) {
        starBtns.forEach(btn => {
            const starVal = parseInt(btn.dataset.rating, 10);
            if (starVal <= rating) {
                btn.classList.add(isHover ? 'hover' : 'active');
            } else {
                btn.classList.remove(isHover ? 'hover' : 'active');
            }
            if (!isHover) {
                btn.classList.remove('hover');
            }
        });
    }

    if (locationSelect) {
        locationSelect.addEventListener('change', scheduleRefresh);
    }

    sortBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            sortBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedSort = btn.dataset.sort;
            scheduleRefresh();
        });
    });

    /* ── Reset filters link ── */
    if (resetLink) {
        resetLink.addEventListener('click', e => {
            e.preventDefault();
            categoryPills.forEach(pill => pill.classList.remove('active'));

            if (minRateInput) minRateInput.value = minRateInput.min;
            if (maxRateInput) maxRateInput.value = maxRateInput.max;
            updateRateSlider();
            selectedMinRating = 0;
            updateStarUI();
            if (locationSelect) locationSelect.selectedIndex = 0;
            if (heroQuery) heroQuery.value = '';
            if (heroLocation) heroLocation.value = '';
            selectedSort = 'recommended';
            sortBtns.forEach(btn => {
                if (btn.dataset.sort === 'recommended') {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            currentPage = 1;
            fetchAndRender(1);
        });
    }

    function syncFiltersFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        
        let urlCategories = urlParams.getAll('category[]');
        if (urlCategories.length === 0) {
            const singleCat = urlParams.get('category');
            if (singleCat) urlCategories = [singleCat];
        }

        if (urlCategories.length > 0) {
            categoryPills.forEach(pill => {
                if (urlCategories.includes(pill.dataset.category)) {
                    pill.classList.add('active');
                } else {
                    pill.classList.remove('active');
                }
            });
        }
        
        const minR = urlParams.get('min-rate');
        if (minR !== null && minRateInput) minRateInput.value = minR;
        
        const maxR = urlParams.get('max-rate');
        if (maxR !== null && maxRateInput) maxRateInput.value = maxR;
        updateRateSlider();
        
        const minRating = urlParams.get('min-rating');
        if (minRating !== null) {
            selectedMinRating = parseInt(minRating, 10) || 0;
            updateStarUI();
        }
        
        const locFilter = urlParams.get('location');
        if (locFilter !== null) {
            if (locationSelect) {
                const optionExists = Array.from(locationSelect.options).some(opt => opt.value === locFilter);
                if (optionExists) {
                    locationSelect.value = locFilter;
                }
            }
            if (heroLocation) heroLocation.value = locFilter;
        }
        
        const query = urlParams.get('query');
        if (query !== null && heroQuery) heroQuery.value = query;
        
        const sort = urlParams.get('sort');
        if (sort !== null) {
            selectedSort = sort;
            sortBtns.forEach(btn => {
                if (btn.dataset.sort === sort) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
    }

    /* ── Initial load on page ready ── */
    syncFiltersFromUrl();
    fetchAndRender(1);
})();

async function initNotificationsCenter() {
    const user = getCurrentUser();
    if (!user) return;

    const notifBtn = document.getElementById('navNotificationBtn');
    const notifDropdown = document.getElementById('navNotificationDropdown');
    const notifBadge = document.getElementById('navNotificationBadge');
    const notifList = document.getElementById('navNotificationList');
    const markAllBtn = document.getElementById('markAllReadBtn');

    if (!notifBtn || !notifDropdown || !notifList) return;

    // Toggle dropdown
    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!notifDropdown.contains(e.target) && e.target !== notifBtn && !notifBtn.contains(e.target)) {
            notifDropdown.style.display = 'none';
        }
    });

    async function fetchNotifications() {
        try {
            const res = await fetch(`ajax_notifications.php?user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                const unreadCount = data.unread_count || 0;
                if (unreadCount > 0) {
                    notifBadge.style.display = 'flex';
                    notifBadge.textContent = unreadCount;
                } else {
                    notifBadge.style.display = 'none';
                }

                const notifications = data.notifications || [];
                if (notifications.length === 0) {
                    notifList.innerHTML = `
                        <div style="padding: 1.5rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                            No notifications yet.
                        </div>
                    `;
                } else {
                    notifList.innerHTML = notifications.map(n => {
                        const unreadClass = !n.is_read ? 'unread' : '';
                        return `
                            <div class="notification-dropdown-item ${unreadClass}" data-id="${n.id}" data-link="${n.link || ''}">
                                <div class="notification-item-content">
                                    <div class="notification-item-title">${localEscapeHtml(n.title)}</div>
                                    <div class="notification-item-message">${localEscapeHtml(n.message)}</div>
                                    <div class="notification-item-time">${n.created_at_fmt}</div>
                                </div>
                            </div>
                        `;
                    }).join('');

                    // Bind item click
                    notifList.querySelectorAll('.notification-dropdown-item').forEach(item => {
                        item.addEventListener('click', async function() {
                            const id = this.dataset.id;
                            const link = this.dataset.link;
                            
                            // Mark as read
                            try {
                                await fetch('ajax_notifications.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        user_id: user.id,
                                        action: 'read',
                                        notification_id: parseInt(id, 10)
                                    })
                                });
                            } catch (err) {
                                console.error('Error marking notification as read:', err);
                            }

                            if (link) {
                                window.location.href = link;
                            } else {
                                fetchNotifications();
                            }
                        });
                    });
                }
            }
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    }

    function localEscapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (markAllBtn) {
        markAllBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                const res = await fetch('ajax_notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: user.id,
                        action: 'read_all'
                    })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    showToast('All notifications marked as read.', 'success');
                    fetchNotifications();
                } else {
                    showToast(result.message || 'Failed to mark notifications as read.', 'error');
                }
            } catch (err) {
                showToast('Network error.', 'error');
            }
        });
    }

    // Initial fetch and 4-second poll loop
    fetchNotifications();
    setInterval(fetchNotifications, 4000);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 5 ── Global auth-state UI updates
   (Runs on every page to show/hide nav items based on login)
   ───────────────────────────────────────────────────────────── */

function updateNavForAuthState() {
    const user = getCurrentUser();
    
    if (user) {
        const path = window.location.pathname.toLowerCase();
        if (path.endsWith('login.html') || path.endsWith('signup.html')) {
            window.location.href = 'homepage.html';
            return;
        }
    }
    
    // Remove direct logout link if it exists (logout is now in dropdown)
    const logoutLink = document.getElementById('navLogoutLink');
    if (logoutLink) logoutLink.remove();

    const userIcon = document.querySelector('.nav-user-icon');
    const userIconLink = document.querySelector('.nav-user-link');

    if (!user) {
        // Show all logged-out auth elements, hide all logged-in auth elements
        document.querySelectorAll('.auth-only-logged-out').forEach(el => {
            el.style.display = '';
        });
        document.querySelectorAll('.auth-only-logged-in').forEach(el => {
            el.style.display = 'none';
        });

        const notifContainer = document.querySelector('.nav-notification-container');
        if (notifContainer) notifContainer.style.display = 'none';

        if (userIconLink) {
            userIconLink.href = 'login.html';
            userIconLink.removeAttribute('title');
            userIconLink.style.position = '';
            userIconLink.style.display = 'none';
        }
        if (userIcon) {
            const dark = document.documentElement.getAttribute('data-theme') === 'dark';
            userIcon.src = dark ? 'images/user-dark.png' : 'images/user-light.png';
            userIcon.style.borderRadius = '';
            userIcon.style.objectFit = '';
        }
        // Show all Become a Buddy links if logged out
        document.querySelectorAll('a[href="become-buddy.html"], a[href*="become-buddy.html"]').forEach(el => {
            el.style.display = '';
        });

        // Remove active dropdown if exists
        const dropdown = document.querySelector('.user-dropdown');
        if (dropdown) dropdown.remove();
        return;
    }

    // Hide Login / Sign up links (including any auth-only-logged-out element)
    document.querySelectorAll('.auth-only-logged-out').forEach(el => {
        el.style.display = 'none';
    });
    
    // Show general auth-only-logged-in elements
    document.querySelectorAll('.auth-only-logged-in').forEach(el => {
        // Skip profile-specific toggles to handle them customly
        if (!el.classList.contains('btn-view-profile-mobile') && 
            !el.classList.contains('btn-become-buddy-mobile') &&
            !el.classList.contains('btn-admin-mobile')) {
            el.style.display = '';
        }
    });

    // Handle mobile profile button and become-buddy button dynamically
    const viewProfileMobile = document.querySelector('.btn-view-profile-mobile');
    const becomeBuddyMobile = document.querySelector('.btn-become-buddy-mobile');

    if (viewProfileMobile) {
        if (user.buddy_profile_id) {
            viewProfileMobile.style.display = '';
            viewProfileMobile.href = `profile.html?id=${user.buddy_profile_id}`;
            viewProfileMobile.textContent = '👤 View My Profile';
        } else {
            viewProfileMobile.style.display = 'none';
        }
    }

    if (becomeBuddyMobile) {
        if (user.buddy_profile_id) {
            becomeBuddyMobile.style.display = 'none';
        } else {
            becomeBuddyMobile.style.display = '';
        }
    }

    // Handle mobile admin link dynamically
    let adminLinkMobile = document.querySelector('.btn-admin-mobile');
    if (!adminLinkMobile && user.role === 'admin') {
        const logoutMobile = document.querySelector('.btn-logout-mobile');
        if (logoutMobile) {
            adminLinkMobile = document.createElement('a');
            adminLinkMobile.href = 'admin.html';
            adminLinkMobile.className = 'auth-only-logged-in btn-admin-mobile';
            adminLinkMobile.style.display = '';
            adminLinkMobile.innerHTML = '🔧 Admin Console';
            logoutMobile.parentNode.insertBefore(adminLinkMobile, logoutMobile);
        }
    } else if (adminLinkMobile) {
        adminLinkMobile.style.display = user.role === 'admin' ? '' : 'none';
    }

    // Update user icon link
    if (userIconLink) {
        userIconLink.style.display = '';
        userIconLink.href  = '#';
        userIconLink.title = `${user.first_name} ${user.last_name}`;
        userIconLink.style.position = 'relative';
        
        if (!userIconLink.dataset.dropdownBound) {
            userIconLink.dataset.dropdownBound = 'true';
            userIconLink.addEventListener('click', (e) => {
                const curUser = getCurrentUser();
                if (curUser) {
                    if (e.target.closest('.user-dropdown')) {
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    toggleUserDropdown();
                }
            });
        }
    }

    // Update user icon src
    if (userIcon) {
        if (user.avatar_url) {
            userIcon.src = user.avatar_url;
            userIcon.style.borderRadius = '50%';
            userIcon.style.objectFit = 'cover';
        } else {
            const dark = document.documentElement.getAttribute('data-theme') === 'dark';
            userIcon.src = dark ? 'images/user-dark.png' : 'images/user-light.png';
            userIcon.style.borderRadius = '';
            userIcon.style.objectFit = '';
        }
    }

    // Update navbar avatar status badge
    const navAvatarStatus = document.getElementById('navAvatarStatus');
    if (navAvatarStatus) {
        const currentStatus = localStorage.getItem('ab_presence_status') || 'online';
        let dotCls = 'presence-dot--online';
        if (currentStatus === 'offline') {
            dotCls = 'presence-dot--offline';
        } else if (currentStatus === 'invisible') {
            dotCls = 'presence-dot--invisible';
        }
        navAvatarStatus.className = `nav-avatar-status-badge ${dotCls}`;
        navAvatarStatus.style.display = 'block';
    }

    // Show notification bell
    const notifContainer = document.querySelector('.nav-notification-container');
    if (notifContainer) {
        notifContainer.style.display = 'inline-block';
        if (!window.hasInitializedNotifications && document.getElementById('navNotificationBtn')) {
            window.hasInitializedNotifications = true;
            initNotificationsCenter();
        }
    }

    // Hide/show all Become a Buddy links depending on if they are already a buddy
    const isBuddy = !!user.buddy_profile_id;
    document.querySelectorAll('a[href="become-buddy.html"], a[href*="become-buddy.html"]').forEach(el => {
        el.style.display = isBuddy ? 'none' : '';
    });
}

function toggleUserDropdown() {
    const userIconLink = document.querySelector('.nav-user-link');
    if (!userIconLink) return;
    
    let dropdown = userIconLink.querySelector('.user-dropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
        return;
    }
    
    const user = getCurrentUser();
    if (!user) return;
    
    const firstName = user.first_name || 'AnyBuddy';
    const lastName = user.last_name || 'User';
    const email = user.email || 'user@anybuddy.ph';
    
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const avatarUrl = user.avatar_url || (isDark ? 'images/user-dark.png' : 'images/user-light.png');
    
    const localEscapeHtml = (str) => {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const profileItemHtml = user.buddy_profile_id 
        ? `<li>
            <a href="profile.html?id=${user.buddy_profile_id}">
                <span class="dropdown-icon">👤</span> View My Profile
            </a>
           </li>`
        : '';

    const adminLinkHtml = user.role === 'admin'
        ? `<li>
            <a href="admin.html">
                <span class="dropdown-icon">🔧</span> Admin Console
            </a>
           </li>`
        : '';

    dropdown = document.createElement('div');
    dropdown.className = 'user-dropdown active';
    
    // Determine current status
    const currentStatus = localStorage.getItem('ab_presence_status') || 'online';
    let statusText = 'Online';
    let statusClass = 'presence-dot--online';
    if (currentStatus === 'offline') {
        statusText = 'Offline';
        statusClass = 'presence-dot--offline';
    } else if (currentStatus === 'invisible') {
        statusText = 'Invisible';
        statusClass = 'presence-dot--invisible';
    }

    dropdown.innerHTML = `
        <div class="user-dropdown-header" style="position: relative;">
            <div style="position: relative; display: inline-flex;">
                <img class="user-dropdown-avatar" src="${avatarUrl}" alt="User Avatar" onerror="this.src='images/AnyBuddy LOGO.png'">
                <span class="avatar-status-badge ${statusClass}" id="dropdownAvatarStatus"></span>
            </div>
            <div style="flex: 1; min-width: 0; text-align: left; display: flex; flex-direction: column; gap: 0.15rem; margin-left: 0.25rem;">
                <div class="user-dropdown-name">${localEscapeHtml(firstName)} ${localEscapeHtml(lastName)}</div>
                <div class="user-dropdown-email" style="font-size: 0.75rem; opacity: 0.75;">${localEscapeHtml(email)}</div>
                <div class="user-presence-selector" style="position: relative; margin-top: 0.25rem; display: inline-block;">
                    <button type="button" class="presence-toggle-btn" id="presenceToggleBtn">
                        <span class="presence-dot ${statusClass}" id="currentPresenceDot"></span>
                        <span id="currentPresenceText">${statusText}</span>
                        <span style="font-size: 0.55rem; opacity: 0.6; margin-left: 0.15rem;">▼</span>
                    </button>
                    <div class="presence-menu" id="presenceMenu">
                        <button type="button" class="presence-option-btn" data-status="online">
                            <span class="presence-dot presence-dot--online"></span>
                            Online
                        </button>
                        <button type="button" class="presence-option-btn" data-status="offline">
                            <span class="presence-dot presence-dot--offline"></span>
                            Offline
                        </button>
                        <button type="button" class="presence-option-btn" data-status="invisible">
                            <span class="presence-dot presence-dot--invisible"></span>
                            Invisible
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <ul class="user-dropdown-menu">
            ${profileItemHtml}
            <li>
                <button type="button" class="btn-edit-profile">
                    <span class="dropdown-icon">✏️</span> Edit Profile
                </button>
            </li>
            <li>
                <a href="bookings.html">
                    <span class="dropdown-icon">📅</span> My Bookings
                </a>
            </li>
            <li>
                <a href="marketplace.html?view=favourites">
                    <span class="dropdown-icon">❤️</span> Favourites
                </a>
            </li>
            ${adminLinkHtml}
            <div class="user-dropdown-divider"></div>
            <li>
                <button type="button" class="btn-logout">
                    <span class="dropdown-icon">🚪</span> Logout
                </button>
            </li>
        </ul>
    `;
    
    userIconLink.appendChild(dropdown);

    // Bind presence selector actions
    const presenceToggleBtn = dropdown.querySelector('#presenceToggleBtn');
    const presenceMenu = dropdown.querySelector('#presenceMenu');
    const currentPresenceDot = dropdown.querySelector('#currentPresenceDot');
    const currentPresenceText = dropdown.querySelector('#currentPresenceText');
    const dropdownAvatarStatus = dropdown.querySelector('#dropdownAvatarStatus');

    presenceToggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        presenceMenu.classList.toggle('active');
    });

    document.addEventListener('click', () => {
        if (presenceMenu) presenceMenu.classList.remove('active');
    });

    dropdown.querySelectorAll('.presence-option-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const selected = btn.dataset.status;
            presenceMenu.classList.remove('active');
            
            // Save state
            localStorage.setItem('ab_presence_status', selected);
            
            // Update local display values
            let displayVal = 'Online';
            let dotCls = 'presence-dot--online';
            if (selected === 'offline') {
                displayVal = 'Offline';
                dotCls = 'presence-dot--offline';
            } else if (selected === 'invisible') {
                displayVal = 'Invisible';
                dotCls = 'presence-dot--invisible';
            }

            currentPresenceText.textContent = displayVal;
            
            // Update dot classes
            currentPresenceDot.className = `presence-dot ${dotCls}`;
            if (dropdownAvatarStatus) dropdownAvatarStatus.className = `avatar-status-badge ${dotCls}`;
            
            // Update nav status dot if it exists
            const navAvatarStatus = document.getElementById('navAvatarStatus');
            if (navAvatarStatus) {
                navAvatarStatus.className = `nav-avatar-status-badge ${dotCls}`;
            }

            // Sync with backend WAMP database
            try {
                await fetch('ajax_user_profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_presence_status',
                        status: selected
                    })
                });
            } catch (err) {
                console.error('Error syncing presence status:', err);
            }
        });
    });
    
    dropdown.querySelector('.btn-edit-profile').addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropdown.classList.remove('active');
        openEditProfileModal();
    });
    
    dropdown.querySelector('.btn-logout').addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        persistAuthState(null);
        showToast('Logged out successfully!', 'info');
        updateNavForAuthState();
        updateHomepageWelcomeMessage();
        
        triggerPortalTransition('Logging out...', 'homepage.html');
    });
    
    // Click outside handler
    const closeDropdown = (e) => {
        if (!userIconLink.contains(e.target)) {
            dropdown.classList.remove('active');
            document.removeEventListener('click', closeDropdown);
        }
    };
    document.addEventListener('click', closeDropdown);
}

// Run updateNavForAuthState immediately on script load as well as DOMContentLoaded
updateNavForAuthState();
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateNavForAuthState);
}

// Global Delegated click listener for mobile drawer links
document.addEventListener('click', (e) => {
    const editMobile = e.target.closest('.btn-edit-profile-mobile');
    if (editMobile) {
        e.preventDefault();
        openEditProfileModal();
        const drawer = document.querySelector('.more-options-drawer');
        const burgerBtn = document.getElementById('burgerToggle');
        if (drawer) drawer.classList.remove('active');
        if (burgerBtn) burgerBtn.classList.remove('active');
    }
    
    const logoutMobile = e.target.closest('.btn-logout-mobile');
    if (logoutMobile) {
        e.preventDefault();
        persistAuthState(null);
        showToast('Logged out successfully!', 'info');
        updateNavForAuthState();
        updateHomepageWelcomeMessage();
        triggerPortalTransition('Goodbye, Buddy! See you soon! 👋', 'homepage.html');
        const drawer = document.querySelector('.more-options-drawer');
        const burgerBtn = document.getElementById('burgerToggle');
        if (drawer) drawer.classList.remove('active');
        if (burgerBtn) burgerBtn.classList.remove('active');
    }
});

/* ─────────────────────────────────────────────────────────────
   SECTION 6 ── Portal Zoom Transition Trigger
   ───────────────────────────────────────────────────────────── */
function triggerPortalTransition(welcomeText, redirectUrl) {
    let overlay = document.querySelector('.portal-transition-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'portal-transition-overlay';
        overlay.innerHTML = `
            <div class="portal-container">
                <div class="portal-ring"></div>
                <div class="portal-ring"></div>
                <div class="portal-ring"></div>
                <div class="portal-text"></div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    
    overlay.querySelector('.portal-text').textContent = welcomeText;
    overlay.classList.add('active');
    
    setTimeout(() => {
        window.location.href = redirectUrl;
    }, 1500);
}

/* ─────────────────────────────────────────────────────────────
   SECTION 7 ── Card Hover Automatic Slideshow & Description
   ───────────────────────────────────────────────────────────── */
(function initCardHoverEffects() {
    // Hover slideshow has been disabled per user request.
    // Buddy card details are shown smoothly via pure CSS transitions (.buddy-card:hover .buddy-hover-bio).
})();

/* ─────────────────────────────────────────────────────────────
   SECTION 8 ── Homepage Sidebar Filters Connection
   ───────────────────────────────────────────────────────────── */
(function initHomepageFilters() {
    const isHomepage = !document.querySelector('.marketplace-container') && document.querySelector('.sidebar');
    if (!isHomepage) return;
    
    function handleHomepageFilterChange() {
        const params = new URLSearchParams();
        
        // Category checkboxes
        const categories = document.querySelectorAll('.sidebar [name="category"]:checked');
        categories.forEach(cb => params.append('category[]', cb.value));
        
        // Sort radio
        const sort = document.querySelector('.sidebar [name="sort"]:checked');
        if (sort && sort.value !== 'recommended') {
            params.set('sort', sort.value);
        }
        
        // Rates
        const minRate = document.querySelector('.sidebar [name="min-rate"]')?.value.trim();
        const maxRate = document.querySelector('.sidebar [name="max-rate"]')?.value.trim();
        if (minRate) params.set('min-rate', minRate);
        if (maxRate) params.set('max-rate', maxRate);
        
        window.location.href = `marketplace.html?${params.toString()}`;
    }
    
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.addEventListener('change', function(e) {
            if (e.target.name === 'category' || e.target.name === 'sort' || e.target.name === 'price-filter') {
                handleHomepageFilterChange();
            }
        });
        sidebar.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && (e.target.name === 'min-rate' || e.target.name === 'max-rate')) {
                handleHomepageFilterChange();
            }
        });
        sidebar.querySelectorAll('[name="min-rate"], [name="max-rate"]').forEach(input => {
            input.addEventListener('blur', () => {
                if (input.value.trim()) {
                    handleHomepageFilterChange();
                }
            });
        });
    }
})();

/* ─────────────────────────────────────────────────────────────
   SECTION 9 ── Newsletter Form Interceptor (Page-wide)
   ───────────────────────────────────────────────────────────── */
document.addEventListener('submit', function (e) {
    const newsletterForm = e.target.closest('.newsletter-form');
    if (!newsletterForm) return;
    
    e.preventDefault();
    const emailInput = newsletterForm.querySelector('input[type="email"]');
    const submitBtn = newsletterForm.querySelector('button[type="submit"]');
    
    if (!emailInput || !submitBtn) return;
    
    const email = emailInput.value.trim();
    if (!email) return;
    
    const origText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Subscribing...';
    
    setTimeout(() => {
        showToast('Subscribed successfully!', 'success');
        emailInput.value = '';
        submitBtn.disabled = false;
        submitBtn.textContent = origText;
    }, 1000);
});

/* ─────────────────────────────────────────────────────────────
   SECTION 10 ── Dynamic Profile Page Loader
   ───────────────────────────────────────────────────────────── */
(async function initProfilePage() {
    const profilePage = document.querySelector('.profile-page');
    if (!profilePage) return;
    
    const params = new URLSearchParams(window.location.search);
    let profileId = params.get('id');
    const user = getCurrentUser();
    
    if (!profileId) {
        if (user && user.buddy_profile_id) {
            profileId = String(user.buddy_profile_id);
        } else {
            // No profile ID and not a buddy -> Redirect to marketplace
            window.location.href = 'marketplace.html';
            return;
        }
    }
    
    let buddy;
    
    try {
        const response = await fetch(`ajax_profile.php?id=${profileId}`);
        const data = await response.json();
        
        if (data.status !== 'success') {
            showToast('Failed to load profile details.', 'error');
            return;
        }
        
        buddy = data.buddy;
        
        const nameHeader = document.querySelector('.profile-header h1');
        const isVerified = (buddy.is_verified || buddy.verification_status === 'verified');
        if (nameHeader) {
            nameHeader.innerHTML = `${buddy.display_name} ${isVerified ? `<span class="verified-badge" title="Verified">✓</span>` : ''}`;
        }

        const titleHeader = document.querySelector('.profile-header .profile-title');
        if (titleHeader) {
            titleHeader.textContent = buddy.professional_title;
            if (buddy.pronouns && buddy.pronouns !== 'Not Specified') {
                const pronounsSpan = document.createElement('span');
                pronounsSpan.className = 'profile-pronouns-tag';
                pronounsSpan.style.marginLeft = '0.5rem';
                pronounsSpan.style.fontSize = '0.85rem';
                pronounsSpan.style.padding = '0.15rem 0.5rem';
                pronounsSpan.style.borderRadius = '12px';
                pronounsSpan.style.background = 'rgba(124, 92, 255, 0.15)';
                pronounsSpan.style.color = 'var(--accent)';
                pronounsSpan.style.fontWeight = 'bold';
                pronounsSpan.textContent = buddy.pronouns;
                titleHeader.appendChild(pronounsSpan);
            }
            let taglineEl = document.querySelector('.profile-header .profile-tagline');
            if (buddy.tagline) {
                if (!taglineEl) {
                    taglineEl = document.createElement('p');
                    taglineEl.className = 'profile-tagline';
                    taglineEl.style.fontStyle = 'italic';
                    taglineEl.style.fontWeight = '600';
                    taglineEl.style.color = 'var(--accent)';
                    taglineEl.style.marginBottom = '0.25rem';
                    titleHeader.parentNode.insertBefore(taglineEl, titleHeader);
                }
                taglineEl.textContent = `"${buddy.tagline}"`;
            } else if (taglineEl) {
                taglineEl.remove();
            }
        }

        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
        const fallbackProfileAvatar = isDarkTheme ? 'images/user-dark.png' : 'images/user-light.png';
        const activeAvatarUrl = (buddy.avatar_url && buddy.avatar_url.trim() !== '' && buddy.avatar_url !== 'images/user-light.png')
            ? buddy.avatar_url
            : fallbackProfileAvatar;

        const avatarImg = document.querySelector('.profile-avatar');
        if (avatarImg) {
            avatarImg.src = activeAvatarUrl;
            avatarImg.alt = buddy.display_name;
            avatarImg.onerror = function() { this.src = fallbackProfileAvatar; };
        }

        // Render dynamic gallery slideshow
        const mainGallery = document.querySelector('.main-gallery');
        const thumbnailRow = document.querySelector('.thumbnail-row');
        if (mainGallery && thumbnailRow) {
            const gallery = buddy.gallery || [];
            if (gallery.length === 0) {
                mainGallery.innerHTML = `
                    <img class="main-gallery-img" src="${activeAvatarUrl}" alt="${buddy.display_name}" onerror="this.src='${fallbackProfileAvatar}'">
                `;
                thumbnailRow.style.display = 'none';
            } else if (gallery.length === 1) {
                mainGallery.innerHTML = `
                    <img class="main-gallery-img" src="${gallery[0]}" alt="${buddy.display_name}">
                `;
                thumbnailRow.style.display = 'none';
            } else {
                thumbnailRow.style.display = 'grid';
                mainGallery.innerHTML = `
                    <img class="main-gallery-img" src="${gallery[0]}" alt="${buddy.display_name}">
                    <div class="carousel-dots" role="tablist" aria-label="Gallery images">
                        ${gallery.map((_, idx) => `
                            <span class="dot ${idx === 0 ? 'is-active' : ''}" data-index="${idx}"></span>
                        `).join('')}
                    </div>
                `;
                
                thumbnailRow.innerHTML = gallery.map((imgUrl, idx) => `
                    <button type="button" class="thumb ${idx === 0 ? 'is-active' : ''}" data-index="${idx}" aria-label="View image ${idx + 1}">
                        <img src="${imgUrl}" alt="">
                    </button>
                `).join('');

                const mainImg = mainGallery.querySelector('.main-gallery-img');
                const thumbs = thumbnailRow.querySelectorAll('.thumb');
                const dots = mainGallery.querySelectorAll('.dot');

                const updateActiveSlide = (index) => {
                    mainImg.src = gallery[index];
                    thumbs.forEach((t, i) => {
                        if (i === index) t.classList.add('is-active');
                        else t.classList.remove('is-active');
                    });
                    dots.forEach((d, i) => {
                        if (i === index) d.classList.add('is-active');
                        else d.classList.remove('is-active');
                    });
                };

                thumbs.forEach((thumb, idx) => {
                    thumb.addEventListener('click', () => updateActiveSlide(idx));
                });
                dots.forEach((dot, idx) => {
                    dot.addEventListener('click', () => updateActiveSlide(idx));
                });
            }
        }

        const overviewText = document.querySelector('.overview-text');
        if (overviewText) {
            overviewText.textContent = buddy.bio;
        }

        const ratingNum = document.querySelector('.rating-num');
        if (ratingNum) {
            ratingNum.textContent = buddy.rating.toFixed(1);
        }

        const reviewCount = document.querySelector('.reviews-score .review-count');
        if (reviewCount) {
            reviewCount.textContent = `(${buddy.review_count} Reviews)`;
        }

        // 1.5. Dynamic Reviews Render
        const reviewsContainer = document.querySelector('.reviews-list-container');
        if (reviewsContainer) {
            let dynamicReviews = buddy.reviews || [];
            if (dynamicReviews.length === 0) {
                reviewsContainer.innerHTML = `
                    <div class="empty-state" style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.95rem;">
                        No reviews yet.
                    </div>
                `;
            } else {
                reviewsContainer.innerHTML = dynamicReviews.map(review => `
                    <div class="review-quote" style="margin-bottom: 1rem; border-radius: 8px; font-style: normal;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; flex-wrap:wrap; gap:0.5rem;">
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <img src="${review.avatar_url}" style="width:1.75rem; height:1.75rem; border-radius:50%; object-fit:cover;" onerror="this.src='images/user-light.png'">
                                <span style="font-weight:700; font-size:0.9rem; font-style: normal;">${review.reviewer_name}</span>
                            </div>
                            <div class="stars">${'★'.repeat(review.rating)}${'☆'.repeat(5 - review.rating)}</div>
                        </div>
                        <div style="font-style:italic; font-size:0.9rem; opacity:0.95;">"${review.text}"</div>
                    </div>
                `).join('');
            }
        }

        const bookBtn = document.querySelector('.book-now-btn');
        if (bookBtn) {
            const currentUser = getCurrentUser();
            if (currentUser && currentUser.id === buddy.user_id) {
                bookBtn.style.display = 'none';
                let selfNotice = document.querySelector('.self-profile-notice');
                if (!selfNotice) {
                    selfNotice = document.createElement('div');
                    selfNotice.className = 'self-profile-notice';
                    selfNotice.textContent = 'This is your own Buddy Profile.';
                    selfNotice.style.color = 'var(--text-secondary)';
                    selfNotice.style.fontSize = '0.9rem';
                    selfNotice.style.fontWeight = '700';
                    selfNotice.style.padding = '0.75rem 1.25rem';
                    selfNotice.style.background = 'var(--bg-muted)';
                    selfNotice.style.border = '1px solid var(--border)';
                    selfNotice.style.borderRadius = '10px';
                    selfNotice.style.textAlign = 'center';
                    selfNotice.style.width = '100%';
                    selfNotice.style.marginTop = '1rem';
                    bookBtn.parentNode.insertBefore(selfNotice, bookBtn);
                }
            } else {
                bookBtn.href = `payment.html?id=${buddy.id}`;
            bookBtn.addEventListener('click', function (e) {
                if (!getCurrentUser()) {
                    e.preventDefault();
                    showToast('You must be logged in to book a Buddy.', 'error');
                    localStorage.setItem('ab_post_login_redirect', `payment.html?id=${buddy.id}`);
                    openAuthModal('login');
                }
            });
            }

            // Dynamically insert favourite heart button next to bookBtn
            let favBtn = document.querySelector('.profile-fav-btn');
            if (!favBtn) {
                favBtn = document.createElement('button');
                favBtn.type = 'button';
                favBtn.className = 'profile-fav-btn';
                favBtn.innerHTML = '♡';
                favBtn.setAttribute('aria-label', 'Add to Favourites');
                bookBtn.parentNode.insertBefore(favBtn, bookBtn.nextSibling);
            }
            
            const updateFavBtnUI = () => {
                const favs = getFavourites();
                const isFav = favs.includes(buddy.id);
                if (isFav) {
                    favBtn.innerHTML = '♥';
                    favBtn.style.color = '#ff4081';
                    favBtn.style.borderColor = '#ff4081';
                    favBtn.style.textShadow = '0 0 8px rgba(255,64,129,0.5)';
                } else {
                    favBtn.innerHTML = '♡';
                    favBtn.style.color = 'var(--text-modal)';
                    favBtn.style.borderColor = 'var(--border-modal)';
                    favBtn.style.textShadow = 'none';
                }
            };
            
            updateFavBtnUI();
            
            favBtn.addEventListener('click', () => {
                const isFav = toggleFavourite(buddy.id);
                if (isFav !== null) {
                    updateFavBtnUI();
                }
            });
        }

        const reportLink = document.querySelector('.report-user-link');
        if (reportLink) {
            reportLink.href = `report.html?user=${encodeURIComponent(buddy.display_name)}&reported_id=${buddy.user_id}`;
        }
        
        document.title = `${buddy.display_name} — AnyBuddy`;
        
        // 2. Quick Info Grid
        const infoItems = document.querySelectorAll('.info-item');
        infoItems.forEach(item => {
            const labelEl = item.querySelector('.info-label');
            const valueEl = item.querySelector('.info-value');
            if (!labelEl || !valueEl) return;
            
            const label = labelEl.textContent.trim().toLowerCase();
            if (label === 'availability') {
                valueEl.textContent = buddy.availability;
            } else if (label === 'verified') {
                valueEl.textContent = buddy.is_verified ? 'Identity Confirmed' : 'Verification Pending';
            } else if (label === 'gender' || label === 'pronouns') {
                labelEl.textContent = 'Pronouns';
                valueEl.textContent = buddy.pronouns || 'Not Specified';
            } else if (label === 'location') {
                valueEl.textContent = buddy.location;
            } else if (label === 'category') {
                valueEl.textContent = buddy.category_label;
            } else if (label === 'rate') {
                valueEl.textContent = `${buddy.hourly_rate_fmt}/hr`;
            }
        });
        
        // 3. Specialties
        const overviewSection = document.querySelector('.profile-section');
        if (overviewSection && buddy.specialties && buddy.specialties.length > 0) {
            let specSection = document.querySelector('.specialties-section');
            if (!specSection) {
                specSection = document.createElement('section');
                specSection.className = 'profile-section specialties-section';
                specSection.innerHTML = `
                    <h2 class="section-label">Specialties</h2>
                    <div class="specialties-list" style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.5rem;"></div>
                `;
                overviewSection.insertAdjacentElement('afterend', specSection);
            }
            const list = specSection.querySelector('.specialties-list');
            list.innerHTML = buddy.specialties.map(spec => `
                <span class="buddy-lang-tag" style="border: 1px solid var(--border); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem;">${spec}</span>
            `).join('');
        }


    } catch (err) {
        showToast('Failed to load profile details.', 'error');
    }
})();

(async function initPaymentPage() {
    const paymentPage = document.querySelector('.payment-page');
    if (!paymentPage) return;
    
    // 1. Guest protection check
    const user = getCurrentUser();
    if (!user) {
        const dest = window.location.pathname.substring(window.location.pathname.lastIndexOf('/') + 1) + window.location.search;
        window.location.href = `login.html?redirect=${encodeURIComponent(dest)}`;
        return;
    }
    
    // Parse Buddy Profile ID from URL ?id=X
    const params = new URLSearchParams(window.location.search);
    const buddyId = parseInt(params.get('id') || '0', 10);
    
    if (buddyId <= 0) {
        showToast('Invalid Buddy selection.', 'error');
        setTimeout(() => { window.location.href = 'marketplace.html'; }, 1500);
        return;
    }
    
    // Get form fields and elements
    const form = document.getElementById('bookingPaymentForm');
    const dateInput = document.getElementById('booking-date');
    const durationInput = document.getElementById('hours-duration');
    const messageInput = document.getElementById('message');
    
    // Set default date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const yyyy = tomorrow.getFullYear();
    const mm = String(tomorrow.getMonth() + 1).padStart(2, '0');
    const dd = String(tomorrow.getDate()).padStart(2, '0');
    if (dateInput) {
        dateInput.value = `${yyyy}-${mm}-${dd}`;
        dateInput.min = `${yyyy}-${mm}-${dd}`;
        
        // Hide default native date picker input
        dateInput.style.display = 'none';
        
        // Inject custom Visual Calendar Availability picker container
        const calendarWrapper = document.createElement('div');
        calendarWrapper.className = 'ab-calendar-wrapper';
        dateInput.parentNode.appendChild(calendarWrapper);
        
        let currentYear = tomorrow.getFullYear();
        let currentMonth = tomorrow.getMonth(); // 0-indexed
        
        async function renderCalendar(year, month) {
            const monthNames = [
                "January", "February", "March", "April", "May", "June",
                "July", "August", "September", "October", "November", "December"
            ];
            
            let bookedDates = [];
            try {
                const response = await fetch(`ajax_availability.php?buddy_profile_id=${buddyId}&month=${month + 1}&year=${year}`);
                const data = await response.json();
                if (data.status === 'success') {
                    bookedDates = data.booked_dates || [];
                }
            } catch (err) {
                console.error("Error fetching booked dates:", err);
            }
            
            const firstDay = new Date(year, month, 1).getDay();
            const totalDays = new Date(year, month + 1, 0).getDate();
            
            calendarWrapper.innerHTML = `
                <div class="ab-calendar-header">
                    <button type="button" class="ab-calendar-nav-btn" id="prevMonthBtn">&lt;</button>
                    <h3>${monthNames[month]} ${year}</h3>
                    <button type="button" class="ab-calendar-nav-btn" id="nextMonthBtn">&gt;</button>
                </div>
                <div class="ab-calendar-weekdays">
                    <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                </div>
                <div class="ab-calendar-grid" id="calendarGrid"></div>
            `;
            
            const grid = calendarWrapper.querySelector('#calendarGrid');
            
            // Empty offset padding cells
            for (let i = 0; i < firstDay; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'ab-calendar-day other-month';
                grid.appendChild(emptyCell);
            }
            
            const todayLimit = new Date();
            todayLimit.setHours(0,0,0,0);
            
            for (let day = 1; day <= totalDays; day++) {
                const dayCell = document.createElement('div');
                dayCell.className = 'ab-calendar-day';
                dayCell.textContent = day;
                
                const cellDate = new Date(year, month, day);
                cellDate.setHours(0,0,0,0);
                
                if (cellDate < todayLimit) {
                    dayCell.classList.add('disabled');
                } else {
                    const y = cellDate.getFullYear();
                    const m = String(cellDate.getMonth() + 1).padStart(2, '0');
                    const d = String(cellDate.getDate()).padStart(2, '0');
                    const formattedDate = `${y}-${m}-${d}`;
                    
                    if (bookedDates.includes(formattedDate)) {
                        dayCell.classList.add('booked');
                        dayCell.title = "Already Booked";
                    } else {
                        if (dateInput.value === formattedDate) {
                            dayCell.classList.add('selected');
                        }
                        
                        dayCell.addEventListener('click', () => {
                            dateInput.value = formattedDate;
                            dateInput.dispatchEvent(new Event('change'));
                            renderCalendar(year, month);
                        });
                    }
                }
                grid.appendChild(dayCell);
            }
            
            calendarWrapper.querySelector('#prevMonthBtn').addEventListener('click', (e) => {
                e.preventDefault();
                currentMonth--;
                if (currentMonth < 0) {
                    currentMonth = 11;
                    currentYear--;
                }
                renderCalendar(currentYear, currentMonth);
            });
            
            calendarWrapper.querySelector('#nextMonthBtn').addEventListener('click', (e) => {
                e.preventDefault();
                currentMonth++;
                if (currentMonth > 11) {
                    currentMonth = 0;
                    currentYear++;
                }
                renderCalendar(currentYear, currentMonth);
            });
        }
        
        renderCalendar(currentYear, currentMonth);
        
        dateInput.addEventListener('change', () => {
            const selectedDate = new Date(dateInput.value);
            if (!isNaN(selectedDate.getTime())) {
                currentYear = selectedDate.getFullYear();
                currentMonth = selectedDate.getMonth();
                renderCalendar(currentYear, currentMonth);
            }
        });
    }

    // Summary elements
    const summaryAvatar = document.getElementById('summary-avatar');
    const summaryName = document.getElementById('summary-name');
    const summaryCategory = document.getElementById('summary-category');
    const summaryHourlyRate = document.getElementById('summary-hourly-rate');
    const summaryDuration = document.getElementById('summary-duration');
    const summarySubtotal = document.getElementById('summary-subtotal');
    const summaryFee = document.getElementById('summary-fee');
    const summaryTotal = document.getElementById('summary-total');
    const btnConfirm = document.getElementById('btnConfirmPayment');
    
    let hourlyRate = 0;
    
    // Payment Method Selection Toggle
    const btnCard = document.getElementById('method-card');
    const btnCoh = document.getElementById('method-coh');
    const cardFormFields = document.querySelector('.card-form-fields');
    
    function toggleCardFields(isCard) {
        if (!cardFormFields) return;
        
        const inputs = cardFormFields.querySelectorAll('input');
        if (isCard) {
            cardFormFields.style.display = 'block';
            inputs.forEach(input => {
                input.setAttribute('required', 'required');
            });
        } else {
            cardFormFields.style.display = 'none';
            inputs.forEach(input => {
                input.removeAttribute('required');
            });
        }
    }
    
    if (btnCard && btnCoh) {
        btnCard.addEventListener('click', () => {
            btnCard.classList.add('is-selected');
            btnCoh.classList.remove('is-selected');
            toggleCardFields(true);
        });
        
        btnCoh.addEventListener('click', () => {
            btnCoh.classList.add('is-selected');
            btnCard.classList.remove('is-selected');
            toggleCardFields(false);
        });
    }
    
    let userTier = {
        tier_name: 'Bronze',
        completed_bookings: 0,
        platform_fee_percent: 5.00,
        discount_percent: 0.00
    };
    let appliedVoucher = null;
    
    // Fetch user tier on page load
    (async function fetchTier() {
        try {
            const tierRes = await fetch(`ajax_get_user_tier.php?user_id=${user.id}`);
            const tierData = await tierRes.json();
            if (tierData.status === 'success') {
                userTier = {
                    tier_name: tierData.tier_name,
                    completed_bookings: tierData.completed_bookings,
                    platform_fee_percent: parseFloat(tierData.platform_fee_percent),
                    discount_percent: parseFloat(tierData.discount_percent)
                };
                updateCalculations();
            }
        } catch (err) {
            console.error("Error fetching user tier:", err);
        }
    })();

    // Fetch buddy details
    try {
        const response = await fetch(`ajax_profile.php?id=${buddyId}`);
        const data = await response.json();
        
        if (data.status !== 'success') {
            showToast('Failed to load buddy profile details.', 'error');
            return;
        }
        
        const buddy = data.buddy;
        if (buddy.user_id === user.id) {
            showToast('You cannot book your own buddy profile.', 'error');
            setTimeout(() => { window.location.href = 'marketplace.html'; }, 1500);
            return;
        }
        hourlyRate = buddy.hourly_rate;
        
        if (summaryAvatar) {
            const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
            const fallbackProfileAvatar = isDarkTheme ? 'images/user-dark.png' : 'images/user-light.png';
            summaryAvatar.src = (buddy.avatar_url && buddy.avatar_url.trim() !== '' && buddy.avatar_url !== 'images/user-light.png')
                ? buddy.avatar_url
                : fallbackProfileAvatar;
            summaryAvatar.onerror = function() { this.src = fallbackProfileAvatar; };
        }
        if (summaryName) summaryName.textContent = buddy.display_name;
        if (summaryCategory) summaryCategory.textContent = buddy.category_label;
        if (summaryHourlyRate) summaryHourlyRate.textContent = `₱${hourlyRate.toFixed(2)}`;
        
        updateCalculations();
        
    } catch (err) {
        showToast('Error connecting to the server for buddy details.', 'error');
    }
    
    function updateCalculations() {
        const duration = parseFloat(durationInput.value) || 1;
        const subtotal = hourlyRate * duration;
        
        // 1. Calculate Tier Discount
        const tierDiscountPercent = parseFloat(userTier.discount_percent) || 0;
        const tierDiscount = subtotal * (tierDiscountPercent / 100.0);
        
        // Update Tier Badge UI
        const tierBadge = document.getElementById('summary-tier-badge');
        if (tierBadge) {
            tierBadge.textContent = `${userTier.tier_name} Tier`;
            if (userTier.tier_name === 'Silver') {
                tierBadge.style.background = '#94a3b8';
            } else if (userTier.tier_name === 'Gold') {
                tierBadge.style.background = '#fbbf24';
            } else if (userTier.tier_name === 'Platinum') {
                tierBadge.style.background = '#38bdf8';
            } else {
                tierBadge.style.background = '#b45309'; // Bronze
            }
        }
        
        // Display Tier Discount row
        const rowTierDiscount = document.getElementById('row-tier-discount');
        const summaryTierDiscountPercent = document.getElementById('summary-tier-discount-percent');
        const summaryTierDiscount = document.getElementById('summary-tier-discount');
        if (rowTierDiscount && summaryTierDiscount) {
            if (tierDiscount > 0) {
                rowTierDiscount.style.display = 'flex';
                if (summaryTierDiscountPercent) summaryTierDiscountPercent.textContent = tierDiscountPercent;
                summaryTierDiscount.textContent = `-₱${tierDiscount.toFixed(2)}`;
            } else {
                rowTierDiscount.style.display = 'none';
            }
        }
        
        // 2. Calculate Voucher Discount
        let voucherDiscount = 0.00;
        if (appliedVoucher) {
            if (appliedVoucher.discount_type === 'fixed') {
                voucherDiscount = parseFloat(appliedVoucher.discount_value);
            } else if (appliedVoucher.discount_type === 'percentage') {
                voucherDiscount = subtotal * (parseFloat(appliedVoucher.discount_value) / 100.0);
            }
            
            // Validate min spend in frontend too
            if (subtotal < parseFloat(appliedVoucher.min_spend)) {
                appliedVoucher = null;
                const vMsg = document.getElementById('voucher-message');
                const vInput = document.getElementById('voucher-code');
                if (vMsg) {
                    vMsg.textContent = 'Voucher removed: Subtotal fell below minimum spend.';
                    vMsg.style.color = '#ef4444';
                }
                if (vInput) vInput.value = '';
                voucherDiscount = 0.00;
            }
        }
        
        const rowVoucherDiscount = document.getElementById('row-voucher-discount');
        const summaryVoucherDiscount = document.getElementById('summary-voucher-discount');
        if (rowVoucherDiscount && summaryVoucherDiscount) {
            if (voucherDiscount > 0) {
                rowVoucherDiscount.style.display = 'flex';
                summaryVoucherDiscount.textContent = `-₱${voucherDiscount.toFixed(2)}`;
            } else {
                rowVoucherDiscount.style.display = 'none';
            }
        }
        
        // 3. Deduct discounts from subtotal
        const totalDiscount = tierDiscount + voucherDiscount;
        const discountedBase = Math.max(0.00, subtotal - totalDiscount);
        
        // 4. Calculate Platform Fee
        const feePercent = parseFloat(userTier.platform_fee_percent);
        const fee = discountedBase * (feePercent / 100.0);
        
        // Update Platform Fee label
        const feeLabel = document.getElementById('summary-fee-label');
        if (feeLabel) {
            feeLabel.textContent = `Platform Fee (${feePercent}%)`;
        }
        
        // 5. Total
        const total = discountedBase + fee;
        
        if (summaryDuration) summaryDuration.textContent = `${duration} hour(s)`;
        if (summarySubtotal) summarySubtotal.textContent = `₱${subtotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        if (summaryFee) summaryFee.textContent = `₱${fee.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        if (summaryTotal) summaryTotal.textContent = `₱${total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        if (btnConfirm) btnConfirm.textContent = `Confirm Payment — ₱${total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
    
    // Voucher Apply Logic
    const btnApplyVoucher = document.getElementById('btn-apply-voucher');
    const voucherInput = document.getElementById('voucher-code');
    const voucherMsg = document.getElementById('voucher-message');
    
    if (btnApplyVoucher && voucherInput && voucherMsg) {
        btnApplyVoucher.addEventListener('click', async () => {
            const code = voucherInput.value.trim().toUpperCase();
            if (code === '') {
                voucherMsg.textContent = 'Please enter a voucher code.';
                voucherMsg.style.color = '#ef4444';
                return;
            }
            
            const duration = parseFloat(durationInput.value) || 1;
            const subtotal = hourlyRate * duration;
            
            try {
                btnApplyVoucher.disabled = true;
                const res = await fetch('ajax_validate_voucher.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: code,
                        base_price: subtotal
                    })
                });
                
                const data = await res.json();
                btnApplyVoucher.disabled = false;
                
                if (data.status === 'success') {
                    appliedVoucher = {
                        code: data.code,
                        discount_type: data.discount_type,
                        discount_value: data.discount_value,
                        min_spend: data.min_spend,
                        voucher_id: data.voucher_id
                    };
                    voucherMsg.textContent = `Success: ₱${parseFloat(data.discount_amount).toFixed(2)} discount applied!`;
                    voucherMsg.style.color = '#10b981';
                    updateCalculations();
                } else {
                    appliedVoucher = null;
                    voucherMsg.textContent = data.message || 'Invalid voucher code.';
                    voucherMsg.style.color = '#ef4444';
                    updateCalculations();
                }
            } catch (err) {
                btnApplyVoucher.disabled = false;
                voucherMsg.textContent = 'Failed to validate voucher.';
                voucherMsg.style.color = '#ef4444';
            }
        });
    }
    
    if (durationInput) {
        const valEl = document.getElementById('duration-value');
        const updateValText = () => {
            if (valEl) {
                const val = parseInt(durationInput.value) || 1;
                valEl.textContent = `${val} hour${val > 1 ? 's' : ''}`;
            }
        };
        durationInput.addEventListener('input', () => {
            updateValText();
            updateCalculations();
        });
        durationInput.addEventListener('change', () => {
            updateValText();
            updateCalculations();
        });
        updateValText();
    }
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            clearAllErrors(form);
            
            const btn = document.getElementById('btnConfirmPayment');
            setButtonLoading(btn, true);
            
            const selectedMethod = (btnCoh && btnCoh.classList.contains('is-selected')) ? 'Cash On Hand' : 'Card';
            
            const payload = {
                user_id: user.id,
                buddy_profile_id: buddyId,
                booking_date: dateInput.value,
                start_time: document.getElementById('start-time').value,
                hours_duration: parseFloat(durationInput.value),
                message: messageInput.value.trim(),
                payment_method: selectedMethod,
                card_number: selectedMethod === 'Card' ? document.getElementById('card-number').value.trim() : '',
                expiration: selectedMethod === 'Card' ? document.getElementById('expiration').value.trim() : '',
                cvv: selectedMethod === 'Card' ? document.getElementById('cvv').value.trim() : '',
                voucher_code: appliedVoucher ? appliedVoucher.code : ''
            };
            
            try {
                const response = await fetch('ajax_bookings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    showToast('Booking requested successfully!', 'success', 3000);
                    form.reset();
                    // Reset fields visibility
                    toggleCardFields(true);
                    if (btnCard) btnCard.classList.add('is-selected');
                    if (btnCoh) btnCoh.classList.remove('is-selected');
                    appliedVoucher = null;
                    if (voucherInput) voucherInput.value = '';
                    if (voucherMsg) voucherMsg.textContent = '';
                    triggerPortalTransition('Booking Confirmed! Your buddy will review it soon. 🎉', 'bookings.html');
                } else {
                    showToast(data.message || 'Payment processing failed.', 'error');
                    if (data.errors) applyFieldErrors(form, data.errors);
                }
            } catch (err) {
                showToast('Network error — please check your connection.', 'error');
            } finally {
                setButtonLoading(btn, false);
                updateCalculations();
            }
        });
    }
})();

/* ─────────────────────────────────────────────────────────────
   SECTION 12 ── User Bookings Page Loader & Cancellations (bookings.html)
   ───────────────────────────────────────────────────────────── */
(async function initBookingsPage() {
    const bookingsPage = document.querySelector('.bookings-page');
    if (!bookingsPage) return;
    
    // 1. Guest protection check
    const user = getCurrentUser();
    if (!user) {
        const dest = window.location.pathname.substring(window.location.pathname.lastIndexOf('/') + 1) + window.location.search;
        window.location.href = `login.html?redirect=${encodeURIComponent(dest)}`;
        return;
    }
    
    const bookingsList = document.querySelector('.bookings-list');
    if (!bookingsList) return;
    
    let currentRole = 'client';
    
    // --- Segmented Tab Toggle for Buddies ---
    const bookingsTabs = document.getElementById('bookingsTabs');
    if (bookingsTabs) {
        const isBuddy = user.is_buddy === true || user.is_buddy === 'true';
        if (isBuddy) {
            bookingsTabs.style.display = 'flex';
        } else {
            bookingsTabs.style.display = 'none';
        }
        
        bookingsTabs.querySelectorAll('.booking-tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                bookingsTabs.querySelectorAll('.booking-tab-btn').forEach(b => b.classList.remove('is-active'));
                this.classList.add('is-active');
                currentRole = this.dataset.role;
                loadBookings();
            });
        });
    }

    // --- Review Modal Elements and State ---
    const reviewModal = document.getElementById('reviewModal');
    const reviewForm = document.getElementById('reviewForm');
    const btnCancelReview = document.getElementById('btnCancelReview');
    const starContainer = document.getElementById('starContainer');
    const reviewComment = document.getElementById('review-comment');
    
    let selectedRating = 0;
    let activeBookingId = null;

    // Set up star buttons once
    if (starContainer) {
        const starBtns = starContainer.querySelectorAll('.star-input-btn');
        starBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                selectedRating = parseInt(this.dataset.value, 10);
                starBtns.forEach(sb => {
                    const val = parseInt(sb.dataset.value, 10);
                    if (val <= selectedRating) {
                        sb.classList.add('active');
                    } else {
                        sb.classList.remove('active');
                    }
                });
            });
        });
    }

    // Modal Close
    function closeModal() {
        if (reviewModal) {
            reviewModal.classList.remove('active');
        }
        activeBookingId = null;
        selectedRating = 0;
    }

    if (btnCancelReview) {
        btnCancelReview.addEventListener('click', closeModal);
    }
    if (reviewModal) {
        reviewModal.addEventListener('click', function(e) {
            if (e.target === reviewModal) {
                closeModal();
            }
        });
    }

    // Form Submit
    if (reviewForm) {
        reviewForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!activeBookingId) {
                showToast('No active booking selected.', 'error');
                return;
            }
            if (selectedRating === 0) {
                showToast('Please select a rating (1-5 stars).', 'error');
                return;
            }
            const commentText = reviewComment ? reviewComment.value.trim() : '';

            try {
                const submitBtn = document.getElementById('btnSubmitReview');
                if (submitBtn) submitBtn.disabled = true;

                const res = await fetch('ajax_reviews.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        booking_id: activeBookingId,
                        user_id: user.id,
                        rating: selectedRating,
                        comment: commentText
                    })
                });

                const result = await res.json();
                if (submitBtn) submitBtn.disabled = false;

                if (result.status === 'success') {
                    showToast(result.message || 'Review submitted successfully!', 'success');
                    closeModal();
                    loadBookings();
                } else {
                    showToast(result.message || 'Failed to submit review.', 'error');
                }
            } catch (err) {
                const submitBtn = document.getElementById('btnSubmitReview');
                if (submitBtn) submitBtn.disabled = false;
                showToast('Network error — failed to submit review.', 'error');
            }
        });
    }

    function renderBuddyEarnings(bookings) {
        const completed = bookings.filter(b => b.status === 'Completed');
        const count = completed.length;
        const hours = completed.reduce((sum, b) => sum + parseFloat(b.hours_duration || 0), 0);
        const gross = completed.reduce((sum, b) => sum + parseFloat(b.total_price || 0), 0);
        const platformFee = gross * (1.0 / 11.0);
        const net = gross - platformFee;

        const formatCurrency = (val) => {
            return '₱' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };

        const panel = document.getElementById('buddyEarningsPanel');
        if (!panel) return;

        panel.innerHTML = `
            <div class="checkout-card" style="padding: 1.5rem; margin-top: 1.5rem;">
                <h3 style="margin: 0 0 1rem 0; font-size: 1.2rem; font-weight: 700; color: var(--accent);">Earnings Analytics</h3>
                <div class="earnings-grid">
                    <div class="earnings-card">
                        <div class="label">Completed Gigs</div>
                        <div class="value">${count}</div>
                    </div>
                    <div class="earnings-card">
                        <div class="label">Total Hours</div>
                        <div class="value">${hours} hrs</div>
                    </div>
                    <div class="earnings-card">
                        <div class="label">Gross Revenue</div>
                        <div class="value">${formatCurrency(gross)}</div>
                    </div>
                    <div class="earnings-card">
                        <div class="label">Platform Fee (10%)</div>
                        <div class="value">${formatCurrency(platformFee)}</div>
                    </div>
                    <div class="earnings-card net-earning">
                        <div class="label" style="color: var(--accent); font-weight: 700;">Net Payout</div>
                        <div class="value">${formatCurrency(net)}</div>
                    </div>
                </div>
            </div>
        `;
    }

    async function renderBuddySlots(buddyProfileId) {
        const panel = document.getElementById('buddySlotsPanel');
        if (!panel) return;

        panel.innerHTML = `
            <div class="checkout-card" style="padding: 1.5rem; margin-top: 1.5rem;">
                <h3 style="margin: 0 0 1rem 0; font-size: 1.2rem; font-weight: 700; color: var(--accent);">Manage Availability Slots</h3>
                
                <form id="addAvailabilitySlotForm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)) auto; gap: 1rem; align-items: end; margin-bottom: 1.5rem;">
                    <div class="form-field" style="margin: 0;">
                        <label for="slot-date" style="font-size: 0.85rem; margin-bottom: 0.35rem;">Date*</label>
                        <input type="date" id="slot-date" name="available_date" required style="width:100%; padding:0.5rem; border-radius:8px; border:1px solid var(--border); background:var(--bg-glass); color:var(--text-primary);">
                    </div>
                    <div class="form-field" style="margin: 0;">
                        <label for="slot-start" style="font-size: 0.85rem; margin-bottom: 0.35rem;">Start Time*</label>
                        <input type="time" id="slot-start" name="start_time" required style="width:100%; padding:0.5rem; border-radius:8px; border:1px solid var(--border); background:var(--bg-glass); color:var(--text-primary);">
                    </div>
                    <div class="form-field" style="margin: 0;">
                        <label for="slot-end" style="font-size: 0.85rem; margin-bottom: 0.35rem;">End Time*</label>
                        <input type="time" id="slot-end" name="end_time" required style="width:100%; padding:0.5rem; border-radius:8px; border:1px solid var(--border); background:var(--bg-glass); color:var(--text-primary);">
                    </div>
                    <button type="submit" class="bab-btn" style="height: 38px; padding: 0 1.5rem; white-space: nowrap; margin-bottom: 2px;">Add Slot</button>
                </form>
                
                <h4 style="margin: 1.5rem 0 0.75rem 0; font-size: 1rem; font-weight: 700;">Active Slots</h4>
                <div class="slots-grid" id="buddySlotsGrid">
                    <div style="color: var(--text-muted); font-size: 0.9rem;">Loading slots...</div>
                </div>
            </div>
        `;

        const tomorrowSlot = new Date();
        tomorrowSlot.setDate(tomorrowSlot.getDate() + 1);
        const yyyy = tomorrowSlot.getFullYear();
        const mm = String(tomorrowSlot.getMonth() + 1).padStart(2, '0');
        const dd = String(tomorrowSlot.getDate()).padStart(2, '0');
        const dateInput = panel.querySelector('#slot-date');
        if (dateInput) {
            dateInput.value = `${yyyy}-${mm}-${dd}`;
            dateInput.min = `${yyyy}-${mm}-${dd}`;
        }

        const grid = panel.querySelector('#buddySlotsGrid');
        
        async function loadSlots() {
            grid.innerHTML = '<div style="color: var(--text-muted); font-size: 0.9rem;">Loading slots...</div>';
            try {
                const res = await fetch(`ajax_availability.php?buddy_profile_id=${buddyProfileId}`);
                const data = await res.json();
                if (data.status === 'success') {
                    const slots = data.slots || [];
                    if (slots.length === 0) {
                        grid.innerHTML = '<div style="color: var(--text-muted); font-size: 0.9rem; grid-column: span 3;">No availability slots added yet. Use the form above to add slots.</div>';
                        return;
                    }
                    
                    grid.innerHTML = '';
                    slots.forEach(slot => {
                        const pill = document.createElement('div');
                        pill.className = `slot-pill ${slot.is_booked ? 'booked' : ''}`;
                        pill.style.position = 'relative';
                        
                        let deleteBtnHtml = '';
                        if (!slot.is_booked) {
                            deleteBtnHtml = `<button type="button" class="delete-slot-btn" data-id="${slot.id}" style="position: absolute; top: 4px; right: 8px; background: none; border: none; color: #ef4444; font-size: 1.2rem; cursor: pointer; line-height: 1;">&times;</button>`;
                        }
                        
                        pill.innerHTML = `
                            ${deleteBtnHtml}
                            <span class="slot-date" style="font-size:0.75rem; opacity:0.7; margin-bottom: 0.25rem;">${slot.available_date}</span>
                            <span class="slot-time">${slot.start_time_fmt} - ${slot.end_time_fmt}</span>
                            <span class="slot-status-label" style="font-size: 0.75rem; margin-top: 0.25rem; font-weight: 600; color: ${slot.is_booked ? '#f59e0b' : '#10b981'};">${slot.is_booked ? 'Booked' : 'Available'}</span>
                        `;
                        
                        const delBtn = pill.querySelector('.delete-slot-btn');
                        if (delBtn) {
                            delBtn.addEventListener('click', async (e) => {
                                e.stopPropagation();
                                if (confirm('Are you sure you want to delete this availability slot?')) {
                                    try {
                                        const delRes = await fetch('ajax_availability.php', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({
                                                action: 'delete',
                                                user_id: user.id,
                                                slot_id: slot.id
                                            })
                                        });
                                        const delData = await delRes.json();
                                        if (delData.status === 'success') {
                                            showToast('Slot deleted successfully!', 'success');
                                            loadSlots();
                                        } else {
                                            showToast(delData.message || 'Failed to delete slot.', 'error');
                                        }
                                    } catch (err) {
                                        showToast('Network error deleting slot.', 'error');
                                    }
                                }
                            });
                        }
                        
                        grid.appendChild(pill);
                    });
                } else {
                    grid.innerHTML = `<div style="color: red; font-size: 0.9rem;">${data.message || 'Error loading slots.'}</div>`;
                }
            } catch (err) {
                grid.innerHTML = '<div style="color: red; font-size: 0.9rem;">Failed to load slots due to network error.</div>';
            }
        }

        const form = panel.querySelector('#addAvailabilitySlotForm');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const dateVal = form.querySelector('[name="available_date"]').value;
            const startVal = form.querySelector('[name="start_time"]').value;
            const endVal = form.querySelector('[name="end_time"]').value;

            try {
                const addRes = await fetch('ajax_availability.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add',
                        user_id: user.id,
                        available_date: dateVal,
                        start_time: startVal,
                        end_time: endVal
                    })
                });
                const addData = await addRes.json();
                if (addData.status === 'success') {
                    showToast('Slot added successfully!', 'success');
                    loadSlots();
                } else {
                    showToast(addData.message || 'Failed to add slot.', 'error');
                }
            } catch (err) {
                showToast('Network error adding slot.', 'error');
            }
        });

        loadSlots();
    }
    
    async function loadBookings() {
        bookingsList.innerHTML = `
            <div style="text-align:center; padding:3rem; opacity:0.6;">
                <p>Loading your bookings...</p>
            </div>
        `;
        
        try {
            const response = await fetch(`ajax_bookings.php?user_id=${user.id}&role=${currentRole}`);
            const data = await response.json();
            
            if (data.status !== 'success') {
                showToast('Failed to load bookings list.', 'error');
                bookingsList.innerHTML = `<p style="text-align:center; padding:2rem; color:red;">Error loading bookings.</p>`;
                return;
            }
            
            const bookings = data.bookings || [];
            
            // Toggle Dashboard View for Buddies
            const dashboard = document.getElementById('buddyDashboardView');
            if (dashboard) {
                if (currentRole === 'buddy') {
                    dashboard.style.display = 'block';
                    renderBuddyEarnings(bookings);
                    if (data.buddy_profile_id) {
                        renderBuddySlots(data.buddy_profile_id);
                    }
                } else {
                    dashboard.style.display = 'none';
                }
            }
            
            if (bookings.length === 0) {
                bookingsList.innerHTML = `
                    <div style="text-align:center; padding:4rem 2rem; border: 1px dashed var(--border); border-radius:16px;">
                        <span style="font-size:3rem; display:block; margin-bottom:1rem;">📅</span>
                        <h3 style="margin:0 0 0.5rem; font-weight:700;">No Bookings Yet</h3>
                        <p style="opacity:0.6; margin:0 0 1.5rem; font-size:0.9rem;">
                            ${currentRole === 'buddy' 
                                ? 'No one has requested a booking with you yet. Keep your profile looking sharp!' 
                                : 'You haven\'t scheduled any buddy dates. Explore our buddy roster to get started!'}
                        </p>
                        ${currentRole === 'buddy' ? '' : '<a href="marketplace.html" class="bab-btn" style="display:inline-block;">Find a Buddy</a>'}
                    </div>
                `;
                return;
            }
            
            bookingsList.innerHTML = bookings.map((b, idx) => {
                // Stepper progress logic based on status
                const isComplete = {
                    requested: b.status === 'Requested' || b.status === 'Accepted' || b.status === 'Verification' || b.status === 'Completed',
                    accepted: b.status === 'Accepted' || b.status === 'Verification' || b.status === 'Completed',
                    verification: b.status === 'Verification' || b.status === 'Completed',
                    completed: b.status === 'Completed'
                };
                
                // Construct Stepper HTML (if not declined)
                let stepperHtml = '';
                if (b.status !== 'Declined') {
                    stepperHtml = `
                        <div class="stepper" aria-label="Booking progress">
                            <div class="stepper-step ${isComplete.requested ? 'is-complete' : ''}">
                                <span class="step-circle">${isComplete.requested ? '✓' : '1'}</span>
                                <span class="step-label">Requested</span>
                            </div>
                            <span class="stepper-line ${isComplete.accepted ? 'is-complete' : ''}" aria-hidden="true"></span>
                            <div class="stepper-step ${isComplete.accepted ? 'is-complete' : ''}">
                                <span class="step-circle">${isComplete.accepted ? '✓' : '2'}</span>
                                <span class="step-label">Accepted</span>
                            </div>
                            <span class="stepper-line ${isComplete.verification ? 'is-complete' : ''}" aria-hidden="true"></span>
                            <div class="stepper-step ${isComplete.verification ? 'is-complete' : ''}">
                                <span class="step-circle">${isComplete.verification ? '✓' : '3'}</span>
                                <span class="step-label">Verification</span>
                            </div>
                            <span class="stepper-line ${isComplete.completed ? 'is-complete' : ''}" aria-hidden="true"></span>
                            <div class="stepper-step ${isComplete.completed ? 'is-complete' : ''}">
                                <span class="step-circle">${isComplete.completed ? '✓' : '4'}</span>
                                <span class="step-label">Completed</span>
                            </div>
                        </div>
                    `;
                }
                
                // Get nice classes based on status
                let cardClass = '';
                let statusBadgeClass = '';
                let statusLabel = b.status;
                if (b.status === 'Requested') {
                    cardClass = 'booking-card--active';
                    statusBadgeClass = 'status-badge--progress';
                    statusLabel = 'Requested (Pending)';
                } else if (b.status === 'Accepted') {
                    cardClass = 'booking-card--active';
                    statusBadgeClass = 'status-badge--progress';
                    statusLabel = 'Accepted';
                } else if (b.status === 'Verification') {
                    cardClass = 'booking-card--active';
                    statusBadgeClass = 'status-badge--progress';
                    statusLabel = 'Verification';
                } else if (b.status === 'Completed') {
                    cardClass = 'booking-card--completed';
                    statusBadgeClass = 'status-badge--completed';
                } else if (b.status === 'Declined') {
                    cardClass = 'booking-card--declined';
                    statusBadgeClass = 'status-badge--declined';
                }
                
                // Show message if present
                const messageHtml = b.message 
                    ? `<div class="request-box"><p>${escapeHtml(b.message)}</p></div>` 
                    : '';
                
                // Construct action buttons HTML
                let actionBtnsHtml = '';
                
                if (currentRole === 'buddy') {
                    // Buddy-specific actions
                    let buddyActionsHtml = '';
                    if (b.status === 'Requested') {
                        buddyActionsHtml = `
                            <button type="button" class="pay-now-btn btn-accept-booking" data-id="${b.id}" style="background-color:#16a34a; border-radius:8px;">Accept</button>
                            <button type="button" class="chat-btn btn-decline-booking" data-id="${b.id}" style="color:#dc2626; border-color:#fca5a5; margin-left:0.5rem;">Decline</button>
                        `;
                    } else if (b.status === 'Accepted') {
                        buddyActionsHtml = `
                            <button type="button" class="pay-now-btn btn-verify-booking" data-id="${b.id}" style="background-color:#2563eb; border-radius:8px;">Verify Meetup</button>
                            <button type="button" class="pay-now-btn btn-complete-booking" data-id="${b.id}" style="background-color:#16a34a; border-radius:8px; margin-left:0.5rem;">Complete Meetup</button>
                        `;
                    } else if (b.status === 'Verification') {
                        buddyActionsHtml = `
                            <button type="button" class="pay-now-btn btn-complete-booking" data-id="${b.id}" style="background-color:#16a34a; border-radius:8px;">Complete Meetup</button>
                        `;
                    }
                    
                    const chatBtnHtml = b.status !== 'Declined'
                        ? `<a href="chat.html?booking_id=${b.id}" class="chat-btn"><span class="chat-icon" aria-hidden="true">💬</span> Open Chat</a>`
                        : `<span class="chat-btn chat-btn--disabled"><span class="chat-icon" aria-hidden="true">💬</span> Open Chat</span>`;
                    
                    actionBtnsHtml = `${chatBtnHtml}${buddyActionsHtml}`;
                } else {
                    // Client-specific actions
                    const cancelBtnHtml = b.status === 'Requested'
                        ? `<button type="button" class="chat-btn btn-cancel-booking" data-id="${b.id}" style="color:#ef4444; border-color:#fca5a5; margin-left:0.5rem;">Cancel Request</button>`
                        : '';
                    
                    const chatBtnHtml = b.status !== 'Declined'
                        ? `<a href="chat.html?booking_id=${b.id}" class="chat-btn"><span class="chat-icon" aria-hidden="true">💬</span> Open Chat</a>`
                        : `<span class="chat-btn chat-btn--disabled"><span class="chat-icon" aria-hidden="true">💬</span> Open Chat</span>`;
                    
                    let safetyShieldBtnHtml = '';
                    if (b.status === 'Accepted' || b.status === 'Verification') {
                        safetyShieldBtnHtml = `
                            <button type="button" class="pay-now-btn btn-activate-safety" data-id="${b.id}" style="background-color: #ef4444; border-radius: 8px; margin-left: 0.5rem; font-weight: bold; border: none; display: inline-flex; align-items: center; gap: 0.25rem;">
                                🛡️ Safety Shield
                            </button>
                        `;
                    }
                    
                    let reviewBtnHtml = '';
                    if (b.status === 'Completed') {
                        if (b.review_id) {
                            reviewBtnHtml = `<span class="reviewed-tag" style="margin-left: 0.5rem; font-size: 0.85rem; font-weight: 600; color: #f5b301; display: inline-flex; align-items: center; gap: 0.25rem;">★ Reviewed</span>`;
                        } else {
                            reviewBtnHtml = `<button type="button" class="chat-btn btn-write-review" data-id="${b.id}" style="color:var(--accent); border-color:var(--accent); margin-left:0.5rem;">★ Write Review</button>`;
                        }
                    }
                    
                    actionBtnsHtml = `${chatBtnHtml}${cancelBtnHtml}${safetyShieldBtnHtml}${reviewBtnHtml}`;
                }
                
                return `
                    <article class="booking-card ${cardClass} scroll-reveal visible" style="animation-delay: ${idx * 0.1}s;">
                        <div class="booking-card-inner">
                            <div class="booking-top">
                                <div class="booking-profile">
                                    <img src="${b.buddy_avatar || b.avatar}" alt="${escapeHtml(b.buddy_name || b.displayName)}" onerror="this.src='images/AnyBuddy LOGO.png'">
                                    <div>
                                        <h2 class="booking-name">${escapeHtml(b.buddy_name || b.displayName)}</h2>
                                        <ul class="booking-meta">
                                            <li>📅 ${b.booking_date_fmt}</li>
                                            <li>⏰ ${b.start_time_fmt}</li>
                                            <li>⏳ ${b.hours_duration} hr(s)</li>
                                            <li>💳 ${escapeHtml(b.payment_method || 'Card')}</li>
                                        </ul>
                                    </div>
                                </div>
                                <span class="status-badge ${statusBadgeClass}">${statusLabel}</span>
                            </div>
 
                            ${messageHtml}
 
                            ${stepperHtml}
 
                            <div class="booking-actions">
                                <div class="booking-action-btns" style="display:flex; align-items:center;">
                                    ${actionBtnsHtml}
                                </div>
                                <div class="booking-price">
                                    <span class="price-total">${b.total_price_fmt}</span>
                                    <span class="price-note">incl. ${b.platform_fee_fmt} platform fee</span>
                                </div>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');
            
            // Helper function for buddy actions
            async function updateBookingStatus(bookingId, action, successMessage) {
                try {
                    const res = await fetch('ajax_bookings.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: action,
                            booking_id: bookingId,
                            user_id: user.id
                        })
                    });
                    
                    const result = await res.json();
                    if (result.status === 'success') {
                        showToast(successMessage, 'success');
                        loadBookings(); // refresh list
                    } else {
                        showToast(result.message || 'Failed to update booking status.', 'error');
                    }
                } catch (err) {
                    showToast('Network error — failed to update booking.', 'error');
                }
            }
 
            // Bind accept buttons
            bookingsList.querySelectorAll('.btn-accept-booking').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    updateBookingStatus(bookingId, 'accept', 'Booking request accepted!');
                });
            });
            
            // Bind decline buttons
            bookingsList.querySelectorAll('.btn-decline-booking').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    if (confirm('Are you sure you want to decline this booking request? This cannot be undone.')) {
                        updateBookingStatus(bookingId, 'decline', 'Booking request declined.');
                    }
                });
            });
            
            // Bind verify buttons
            bookingsList.querySelectorAll('.btn-verify-booking').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    updateBookingStatus(bookingId, 'verify', 'Booking moved to verification stage.');
                });
            });
            
            // Bind complete buttons
            bookingsList.querySelectorAll('.btn-complete-booking').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    updateBookingStatus(bookingId, 'complete', 'Booking marked as completed!');
                });
            });
 
            // Bind cancel buttons
            bookingsList.querySelectorAll('.btn-cancel-booking').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    if (confirm('Are you sure you want to cancel this booking request? This cannot be undone.')) {
                        try {
                            const res = await fetch('ajax_bookings.php', {
                                 method: 'POST',
                                 headers: { 'Content-Type': 'application/json' },
                                 body: JSON.stringify({
                                     action: 'cancel',
                                     booking_id: bookingId,
                                     user_id: user.id
                                 })
                            });
                             
                            const result = await res.json();
                            if (result.status === 'success') {
                                showToast('Booking cancelled successfully!', 'success');
                                loadBookings(); // refresh list
                            } else {
                                showToast(result.message || 'Failed to cancel booking.', 'error');
                            }
                        } catch (err) {
                            showToast('Network error — failed to cancel booking.', 'error');
                        }
                    }
                });
            });
            
            // Bind review buttons
            bookingsList.querySelectorAll('.btn-write-review').forEach(btn => {
                btn.addEventListener('click', function() {
                    activeBookingId = parseInt(this.dataset.id, 10);
                    selectedRating = 0;
                    if (reviewComment) reviewComment.value = '';
                    
                    // Reset star UI
                    if (starContainer) {
                        starContainer.querySelectorAll('.star-input-btn').forEach(sb => {
                            sb.classList.remove('active');
                        });
                    }
                    
                    if (reviewModal) {
                        reviewModal.classList.add('active');
                    }
                });
            });

            // Bind activate safety buttons
            bookingsList.querySelectorAll('.btn-activate-safety').forEach(btn => {
                btn.addEventListener('click', async function() {
                    const bookingId = parseInt(this.dataset.id, 10);
                    try {
                        const resProfile = await fetch(`ajax_user_profile.php?user_id=${user.id}`);
                        const profileData = await resProfile.json();
                        
                        if (profileData.status !== 'success') {
                            showToast('Failed to load profile details.', 'error');
                            return;
                        }
                        
                        const curUser = profileData.user;
                        
                        if (!curUser.emergency_name || (!curUser.emergency_email && !curUser.emergency_phone)) {
                            showToast('Emergency contact not configured! Please configure it in your profile first.', 'error');
                            openEditProfileModal();
                            return;
                        }
                        
                        let safetyOverlay = document.querySelector('.safety-overlay');
                        if (!safetyOverlay) {
                            safetyOverlay = document.createElement('div');
                            safetyOverlay.className = 'info-modal-overlay safety-overlay';
                            document.body.appendChild(safetyOverlay);
                        }
                        
                        safetyOverlay.innerHTML = `
                            <div class="info-modal-card">
                                <button type="button" class="info-modal-close" id="btnCancelSafety">&times;</button>
                                <h3 style="margin: 0 0 1rem 0; font-size: 1.3rem; font-weight: 700; color: #ef4444; display: flex; align-items: center; gap: 0.5rem;">
                                    🛡️ Activate Safety Shield
                                </h3>
                                <p style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 1.25rem;">
                                    Please complete the safety checklist to activate the shield. This will prepare a simulated emergency check-in alert.
                                </p>
                                
                                <div class="safety-modal-body">
                                    <div class="safety-emergency-info">
                                        <h4>Registered Emergency Contact</h4>
                                        <div class="safety-emergency-row">
                                            <strong>Name:</strong> <span id="safety-em-name">${escapeHtml(curUser.emergency_name)}</span>
                                        </div>
                                        <div class="safety-emergency-row">
                                            <strong>Email:</strong> <span id="safety-em-email">${escapeHtml(curUser.emergency_email || 'N/A')}</span>
                                        </div>
                                        <div class="safety-emergency-row">
                                            <strong>Phone:</strong> <span id="safety-em-phone">${escapeHtml(curUser.emergency_phone || 'N/A')}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="safety-checklist">
                                        <label class="safety-check-item">
                                            <input type="checkbox" class="safety-checkbox">
                                            <div class="safety-check-text">
                                                <strong>Safe Meeting Location</strong>
                                                I am meeting in a public, safe environment or have verified the venue details.
                                            </div>
                                        </label>
                                        <label class="safety-check-item">
                                            <input type="checkbox" class="safety-checkbox">
                                            <div class="safety-check-text">
                                                <strong>Emergency Contact Notified</strong>
                                                My emergency contact knows I am using AnyBuddy and expects me to check in.
                                            </div>
                                        </label>
                                        <label class="safety-check-item">
                                            <input type="checkbox" class="safety-checkbox">
                                            <div class="safety-check-text">
                                                <strong>Device Prepared</strong>
                                                My phone is charged, and I have signal/internet to receive updates or contact support.
                                            </div>
                                        </label>
                                    </div>
                                    
                                    <button type="button" class="bab-btn" id="btnConfirmSafetyAlert" disabled style="background-color: #ef4444; width: 100%; border: none; font-weight: bold; margin-top: 0.5rem;">
                                        Dispatch Safety Alert
                                    </button>
                                </div>
                            </div>
                        `;
                        
                        safetyOverlay.classList.add('active');
                        
                        const closeSafetyModal = () => {
                            safetyOverlay.classList.remove('active');
                        };
                        
                        safetyOverlay.querySelector('#btnCancelSafety').addEventListener('click', closeSafetyModal);
                        safetyOverlay.addEventListener('click', (e) => {
                            if (e.target === safetyOverlay) {
                                closeSafetyModal();
                            }
                        });
                        
                        const checkboxes = safetyOverlay.querySelectorAll('.safety-checkbox');
                        const dispatchBtn = safetyOverlay.querySelector('#btnConfirmSafetyAlert');
                        
                        checkboxes.forEach(cb => {
                            cb.addEventListener('change', () => {
                                const allChecked = Array.from(checkboxes).every(c => c.checked);
                                dispatchBtn.disabled = !allChecked;
                            });
                        });
                        
                        dispatchBtn.addEventListener('click', async () => {
                            setButtonLoading(dispatchBtn, true);
                            try {
                                const alertRes = await fetch('ajax_safety_alert.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        user_id: user.id,
                                        booking_id: bookingId
                                    })
                                });
                                const alertData = await alertRes.json();
                                if (alertData.status === 'success') {
                                    showToast(alertData.message || 'Safety Alert activated successfully!', 'success');
                                    closeSafetyModal();
                                    loadBookings();
                                } else {
                                    showToast(alertData.message || 'Failed to activate Safety Alert.', 'error');
                                }
                            } catch (err) {
                                showToast('Network error activating safety alert.', 'error');
                            } finally {
                                setButtonLoading(dispatchBtn, false);
                            }
                        });
                        
                    } catch (err) {
                        showToast('Error checking emergency contacts.', 'error');
                    }
                });
            });
            
        } catch (err) {
            bookingsList.innerHTML = `<p style="text-align:center; padding:2rem; color:red;">Network error loading bookings.</p>`;
        }
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
    // Initial load
    loadBookings();
})();

/* ─────────────────────────────────────────────────────────────
   SECTION 13 ── User Dropdown & Profile Editing & Scroll Animation
   ───────────────────────────────────────────────────────────── */
async function openEditProfileModal() {
    const user = getCurrentUser();
    if (!user) return;
    
    let overlay = document.querySelector('.edit-profile-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'edit-profile-overlay';
        document.body.appendChild(overlay);
    }
    
    // Close modal function
    const closeModal = () => {
        overlay.classList.remove('active');
        setTimeout(() => overlay.remove(), 300);
        // Refresh home welcome message
        if (typeof updateHomepageWelcomeMessage === 'function') {
            updateHomepageWelcomeMessage();
        }
        if (typeof updateNavForAuthState === 'function') {
            updateNavForAuthState();
        }
    };

    overlay.innerHTML = `
        <div class="edit-profile-card">
            <div class="settings-dashboard-header">
                <h3>Settings Dashboard</h3>
                <button type="button" class="settings-dashboard-close" id="closeSettingsBtn">&times;</button>
            </div>
            <div class="settings-dashboard-layout">
                <!-- Left Sidebar Tabs -->
                <aside class="settings-tabs" id="settingsTabsContainer">
                    <button type="button" class="settings-tab-btn active" data-tab="info">👤 Information</button>
                    <button type="button" class="settings-tab-btn" data-tab="tier">👑 Loyalty Tier</button>
                    <button type="button" class="settings-tab-btn" data-tab="history">📅 Booking History</button>
                    <button type="button" class="settings-tab-btn" data-tab="payment">💳 Payment Methods</button>
                    <button type="button" class="settings-tab-btn" data-tab="buddy" id="buddyTabBtn">🌟 Become a Buddy</button>
                </aside>
                
                <!-- Right Tab Contents -->
                <div class="settings-tab-content active" id="tab-info">
                    <p style="text-align: center; color: var(--text-modal); margin-top: 3rem;">Loading profile details...</p>
                </div>
                
                <div class="settings-tab-content" id="tab-tier">
                    <p style="text-align: center; color: var(--text-modal); margin-top: 3rem;">Loading tier status...</p>
                </div>
                
                <div class="settings-tab-content" id="tab-history">
                    <p style="text-align: center; color: var(--text-modal); margin-top: 3rem;">Loading booking history...</p>
                </div>
                
                <div class="settings-tab-content" id="tab-payment">
                    <p style="text-align: center; color: var(--text-modal); margin-top: 3rem;">Loading payment methods...</p>
                </div>
                
                <div class="settings-tab-content" id="tab-buddy">
                    <p style="text-align: center; color: var(--text-modal); margin-top: 3rem;">Loading buddy registration...</p>
                </div>
            </div>
        </div>
    `;
    overlay.classList.add('active');

    // Bind close actions
    overlay.querySelector('#closeSettingsBtn').addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeModal();
    });

    // Load initial data
    let dbData = null;
    try {
        const res = await fetch(`ajax_user_profile.php?user_id=${user.id}`);
        dbData = await res.json();
    } catch (err) {
        console.error('Error fetching dashboard details:', err);
    }

    if (!dbData || dbData.status !== 'success') {
        showToast('Failed to load profile settings data.', 'error');
        closeModal();
        return;
    }

    const userData = dbData.user;
    const languagesLookup = dbData.languages_list || [];
    const specialtiesLookup = dbData.specialties_list || [];
    const bookingsList = dbData.bookings || [];
    const cardsList = dbData.payment_methods || [];
    const isBuddy = userData.role === 'buddy' || userData.buddy_profile_id !== null;
    
    // Update Become a Buddy tab title if they are already a buddy
    const buddyTabBtn = overlay.querySelector('#buddyTabBtn');
    if (buddyTabBtn) {
        if (isBuddy) {
            buddyTabBtn.innerHTML = '⚙️ Buddy Settings';
        }
    }

    // ── Wire Tab Toggling ──
    const tabButtons = overlay.querySelectorAll('.settings-tab-btn');
    const tabContents = overlay.querySelectorAll('.settings-tab-content');
    
    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            tabButtons.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            btn.classList.add('active');
            const targetId = `tab-${btn.dataset.tab}`;
            overlay.querySelector(`#${targetId}`).classList.add('active');
        });
    });

    // ────────────────────────────────────────────────────────
    // TAB 1: PERSONAL INFORMATION
    // ────────────────────────────────────────────────────────
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const avatarUrl = userData.avatar_url || (isDark ? 'images/user-dark.png' : 'images/user-light.png');
    
    const infoTab = overlay.querySelector('#tab-info');
    infoTab.innerHTML = `
        <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);">Personal Profile Information</h4>
        <img class="edit-profile-avatar-preview" id="infoAvatarPreview" src="${avatarUrl}" alt="Avatar Preview" onerror="this.src='images/AnyBuddy LOGO.png'">
        
        <form id="infoForm">
            <div class="edit-profile-field">
                <label>Change Avatar Picture</label>
                <div class="image-upload-zone" id="info-avatar-upload-zone" style="cursor: pointer; padding: 1.5rem; text-align: center; border: 1.5px dashed var(--border-modal); border-radius: 12px; background: var(--bg-modal-input);">
                    <input type="file" id="info-avatar-file" accept="image/*" style="display: none;">
                    <div class="upload-zone-content" id="info-avatar-upload-content" style="${userData.avatar_url ? 'display: none;' : ''}">
                        <span>Drag & drop or <span class="upload-browse" style="color: var(--accent); text-decoration: underline; font-weight: 700;">browse</span> to upload avatar</span>
                    </div>
                    <div class="upload-zone-preview" id="info-avatar-upload-preview" style="${userData.avatar_url ? 'display: flex; justify-content: center; align-items: center; gap: 1rem;' : 'display: none;'}">
                        <span style="font-size: 0.85rem; color: var(--text-modal);">Image Selected</span>
                        <button type="button" class="card-delete-btn" id="clearAvatarBtn" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">Remove</button>
                    </div>
                </div>
            </div>
            
            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="info-first-name">First Name</label>
                    <input type="text" id="info-first-name" name="first_name" value="${userData.first_name || ''}" required>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="info-last-name">Last Name</label>
                    <input type="text" id="info-last-name" name="last_name" value="${userData.last_name || ''}" required>
                </div>
            </div>
            
            <div class="edit-profile-field">
                <label for="info-pronouns">Pronouns</label>
                <input type="text" id="info-pronouns" name="pronouns" placeholder="e.g. they/them, he/him, she/her" value="${userData.pronouns || ''}">
            </div>
            
            <div class="edit-profile-field">
                <label for="info-bio">About Me / Bio</label>
                <textarea id="info-bio" name="bio" rows="3" placeholder="Tell us about yourself...">${userData.bio || ''}</textarea>
            </div>
            
            <h4 style="margin: 1.5rem 0 0.75rem; font-size: 1rem; font-weight: 700; color: var(--accent); border-top: 1px solid var(--border-glass); padding-top: 1rem;">Emergency Contact</h4>
            <div class="edit-profile-field">
                <label for="info-em-name">Contact Name</label>
                <input type="text" id="info-em-name" name="emergency_name" placeholder="Full Name" value="${userData.emergency_name || ''}">
            </div>
            <div class="form-row" style="display: flex; gap: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="info-em-email">Contact Email</label>
                    <input type="email" id="info-em-email" name="emergency_email" placeholder="email@example.com" value="${userData.emergency_email || ''}">
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="info-em-phone">Contact Phone</label>
                    <input type="text" id="info-em-phone" name="emergency_phone" placeholder="e.g. +639171234567" value="${userData.emergency_phone || ''}">
                </div>
            </div>
            
            <div class="edit-profile-actions" style="margin-top: 1.5rem;">
                <button type="submit" class="edit-profile-save">Save Info Changes</button>
            </div>
        </form>
    `;

    // Information Avatar Base64 state
    let infoAvatarBase64 = null;
    let infoAvatarUrl = userData.avatar_url || '';
    
    const infoAvatarFile = infoTab.querySelector('#info-avatar-file');
    const infoAvatarZone = infoTab.querySelector('#info-avatar-upload-zone');
    const infoAvatarContent = infoTab.querySelector('#info-avatar-upload-content');
    const infoAvatarPreviewDiv = infoTab.querySelector('#info-avatar-upload-preview');
    const infoAvatarImg = infoTab.querySelector('#infoAvatarPreview');
    const clearAvatarBtn = infoTab.querySelector('#clearAvatarBtn');
    
    infoAvatarZone.addEventListener('click', (e) => {
        if (e.target !== clearAvatarBtn && !clearAvatarBtn.contains(e.target)) {
            infoAvatarFile.click();
        }
    });
    
    infoAvatarFile.addEventListener('change', () => {
        if (infoAvatarFile.files.length > 0) {
            const file = infoAvatarFile.files[0];
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = () => {
                infoAvatarBase64 = reader.result;
                infoAvatarImg.src = reader.result;
                infoAvatarContent.style.display = 'none';
                infoAvatarPreviewDiv.style.display = 'flex';
            };
        }
    });
    
    clearAvatarBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        infoAvatarFile.value = '';
        infoAvatarBase64 = '';
        infoAvatarUrl = '';
        infoAvatarImg.src = isDark ? 'images/user-dark.png' : 'images/user-light.png';
        infoAvatarContent.style.display = 'block';
        infoAvatarPreviewDiv.style.display = 'none';
    });

    // Info submit save handler
    infoTab.querySelector('#infoForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const saveBtn = infoTab.querySelector('.edit-profile-save');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving changes...';
        
        const payload = {
            user_id: user.id,
            action: 'update_personal_info',
            first_name: infoTab.querySelector('#info-first-name').value.trim(),
            last_name: infoTab.querySelector('#info-last-name').value.trim(),
            pronouns: infoTab.querySelector('#info-pronouns').value.trim(),
            bio: infoTab.querySelector('#info-bio').value.trim(),
            emergency_name: infoTab.querySelector('#info-em-name').value.trim(),
            emergency_email: infoTab.querySelector('#info-em-email').value.trim(),
            emergency_phone: infoTab.querySelector('#info-em-phone').value.trim(),
            avatar_url: infoAvatarUrl,
            avatar_image_data: infoAvatarBase64
        };
        
        try {
            const res = await fetch('ajax_user_profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.status === 'success') {
                user.first_name = payload.first_name;
                user.last_name = payload.last_name;
                user.pronouns = payload.pronouns;
                user.bio = data.bio;
                user.avatar_url = data.avatar_url;
                persistAuthState(user);
                showToast('Information changes saved successfully!', 'success');
                infoAvatarUrl = data.avatar_url;
                infoAvatarBase64 = null;
                infoAvatarContent.style.display = data.avatar_url ? 'none' : 'block';
                infoAvatarPreviewDiv.style.display = data.avatar_url ? 'flex' : 'none';
            } else {
                showToast(data.message || 'Failed to save changes.', 'error');
            }
        } catch (err) {
            showToast('Network error saving info changes.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Info Changes';
        }
    });

    // ────────────────────────────────────────────────────────
    // TAB 2: LOYALTY TIER STATUS
    // ────────────────────────────────────────────────────────
    const completedGigs = dbData.completed_bookings || 0;
    const currentTier = dbData.loyalty_tier || { tier_name: 'Bronze', platform_fee_percent: 5, discount_percent: 0 };
    
    // Determine next tier logic
    let nextTierName = 'Platinum';
    let bookingsToNext = 0;
    let nextTierPercentage = 100;
    
    if (completedGigs < 3) {
        nextTierName = 'Silver';
        bookingsToNext = 3 - completedGigs;
        nextTierPercentage = Math.round((completedGigs / 3) * 100);
    } else if (completedGigs < 8) {
        nextTierName = 'Gold';
        bookingsToNext = 8 - completedGigs;
        nextTierPercentage = Math.round(((completedGigs - 3) / 5) * 100);
    } else if (completedGigs < 15) {
        nextTierName = 'Platinum';
        bookingsToNext = 15 - completedGigs;
        nextTierPercentage = Math.round(((completedGigs - 8) / 7) * 100);
    } else {
        nextTierName = 'Max Tier Reached';
        bookingsToNext = 0;
        nextTierPercentage = 100;
    }
    
    const tierClass = currentTier.tier_name.toLowerCase();
    
    const tierTab = overlay.querySelector('#tab-tier');
    tierTab.innerHTML = `
        <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);">Loyalty Reward Tier</h4>
        <div class="tier-progress-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 1.1rem; font-weight: 700; color: var(--text-modal);">Current Loyalty Tier</span>
                <span class="tier-badge tier-badge--${tierClass}">${currentTier.tier_name} Tier</span>
            </div>
            <p style="font-size: 0.85rem; color: var(--text-modal); opacity: 0.85; margin: 0.25rem 0 0.75rem;">
                You have completed <strong>${completedGigs}</strong> booking sessions.
            </p>
            
            <div class="tier-progress-bar-container">
                <div class="tier-progress-bar" style="width: ${nextTierPercentage}%;"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; opacity: 0.85; margin-top: 0.5rem; color: var(--text-modal);">
                <span>Progress: ${nextTierPercentage}%</span>
                <span>${bookingsToNext > 0 ? `${bookingsToNext} more bookings until ${nextTierName}` : 'Highest tier achieved! 🎉'}</span>
            </div>
        </div>
        
        <h4 style="margin: 1.5rem 0 0.75rem; font-size: 1rem; font-weight: 700; color: var(--accent);">Loyalty Benefits Table</h4>
        <div style="overflow-x: auto; background: rgba(0,0,0,0.1); border-radius: 12px; border: 1px solid var(--border-glass);">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; color: var(--text-modal);">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-glass); background: rgba(255,255,255,0.02);">
                        <th style="padding: 0.75rem 1rem;">Tier</th>
                        <th style="padding: 0.75rem 1rem;">Bookings Required</th>
                        <th style="padding: 0.75rem 1rem;">Platform Fee</th>
                        <th style="padding: 0.75rem 1rem;">Booking Discount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid var(--border-glass); ${currentTier.tier_name === 'Bronze' ? 'background: rgba(0, 210, 255, 0.08); font-weight:700;' : ''}">
                        <td style="padding: 0.75rem 1rem;">Bronze</td>
                        <td style="padding: 0.75rem 1rem;">0 - 2</td>
                        <td style="padding: 0.75rem 1rem;">5.0%</td>
                        <td style="padding: 0.75rem 1rem;">0%</td>
                    </tr>
                    <tr style="border-bottom: 1px solid var(--border-glass); ${currentTier.tier_name === 'Silver' ? 'background: rgba(0, 210, 255, 0.08); font-weight:700;' : ''}">
                        <td style="padding: 0.75rem 1rem;">Silver</td>
                        <td style="padding: 0.75rem 1rem;">3 - 7</td>
                        <td style="padding: 0.75rem 1rem;">4.0%</td>
                        <td style="padding: 0.75rem 1rem;">2%</td>
                    </tr>
                    <tr style="border-bottom: 1px solid var(--border-glass); ${currentTier.tier_name === 'Gold' ? 'background: rgba(0, 210, 255, 0.08); font-weight:700;' : ''}">
                        <td style="padding: 0.75rem 1rem;">Gold</td>
                        <td style="padding: 0.75rem 1rem;">8 - 14</td>
                        <td style="padding: 0.75rem 1rem;">3.0%</td>
                        <td style="padding: 0.75rem 1rem;">5%</td>
                    </tr>
                    <tr style="${currentTier.tier_name === 'Platinum' ? 'background: rgba(0, 210, 255, 0.08); font-weight:700;' : ''}">
                        <td style="padding: 0.75rem 1rem;">Platinum</td>
                        <td style="padding: 0.75rem 1rem;">15+</td>
                        <td style="padding: 0.75rem 1rem;">1.5%</td>
                        <td style="padding: 0.75rem 1rem;">8%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    `;

    // ────────────────────────────────────────────────────────
    // TAB 3: BOOKING HISTORY
    // ────────────────────────────────────────────────────────
    const historyTab = overlay.querySelector('#tab-history');
    
    if (bookingsList.length === 0) {
        historyTab.innerHTML = `
            <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);">My Booking History</h4>
            <div style="padding: 3rem; text-align: center; color: var(--text-modal); opacity: 0.65; border: 1.5px dashed var(--border-modal); border-radius: 16px;">
                No bookings found. You haven't made any sessions yet.
            </div>
        `;
    } else {
        const historyCardsHtml = bookingsList.map(b => {
            const isClientRole = (userData.role === 'client');
            const partnerName = isClientRole ? b.buddy_name : b.client_name;
            let statusClass = 'status-badge--progress';
            if (b.status === 'Completed') statusClass = 'status-badge--completed';
            else if (b.status === 'Cancelled' || b.status === 'Declined') statusClass = 'status-badge--declined';
            
            return `
                <div class="history-item-card">
                    <div style="text-align: left;">
                        <h4 style="margin: 0 0 0.25rem; font-weight: 700; color: var(--text-modal);">${escapeHtml(partnerName)}</h4>
                        <span style="font-size: 0.8rem; color: var(--text-modal); opacity: 0.8;">
                            📅 ${escapeHtml(b.booking_date)} • 🕒 ${escapeHtml(b.start_time)}
                        </span>
                        <div style="font-size: 0.75rem; color: var(--text-modal); opacity: 0.75; margin-top: 0.2rem;">
                            Base: ₱${b.base_price} • Fee: ₱${b.platform_fee} • Disc: ₱${b.discount_amount}
                        </div>
                    </div>
                    <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
                        <span style="font-weight: 700; font-size: 1rem; color: var(--accent);">₱${b.total_price}</span>
                        <span class="status-badge ${statusClass}" style="font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 6px;">
                            ${b.status}
                        </span>
                    </div>
                </div>
            `;
        }).join('');
        
        historyTab.innerHTML = `
            <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);">My Booking History</h4>
            <div class="history-list" style="max-height: 58vh; overflow-y: auto;">
                ${historyCardsHtml}
            </div>
        `;
    }

    // ────────────────────────────────────────────────────────
    // TAB 4: PAYMENT METHODS
    // ────────────────────────────────────────────────────────
    const paymentTab = overlay.querySelector('#tab-payment');
    
    // Local memory list of cards to enable instant list updates
    let localCards = [...cardsList];
    
    function renderCards() {
        const cardsContainer = paymentTab.querySelector('#cardsContainer');
        if (!cardsContainer) return;
        
        if (localCards.length === 0) {
            cardsContainer.innerHTML = `
                <div style="padding: 2rem; text-align: center; color: var(--text-modal); opacity: 0.65; border: 1.5px dashed var(--border-modal); border-radius: 12px; margin-bottom: 1.5rem;">
                    No payment methods saved. Add one below!
                </div>
            `;
            return;
        }
        
        cardsContainer.innerHTML = localCards.map(c => {
            return `
                <div class="payment-card-item" data-card-id="${c.id}">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span class="payment-card-icon">💳</span>
                        <div style="text-align: left;">
                            <h5 style="margin: 0 0 0.15rem; font-weight: 700; color: var(--text-modal);">${escapeHtml(c.card_number)}</h5>
                            <span style="font-size: 0.75rem; color: var(--text-modal); opacity: 0.85;">
                                Holder: ${escapeHtml(c.cardholder_name)} • Expires: ${escapeHtml(c.expiry_date)}
                            </span>
                        </div>
                    </div>
                    <button type="button" class="card-delete-btn delete-card-trigger" data-card-id="${c.id}">Delete</button>
                </div>
            `;
        }).join('');
        
        // Bind delete card buttons
        cardsContainer.querySelectorAll('.delete-card-trigger').forEach(btn => {
            btn.addEventListener('click', async () => {
                const cardId = Number(btn.dataset.cardId);
                btn.disabled = true;
                btn.textContent = 'Deleting...';
                
                try {
                    const res = await fetch('ajax_user_profile.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            user_id: user.id,
                            action: 'delete_payment_card',
                            card_id: cardId
                        })
                    });
                    const result = await res.json();
                    if (result.status === 'success') {
                        localCards = localCards.filter(c => c.id !== cardId);
                        showToast('Card deleted successfully!', 'success');
                        renderCards();
                    } else {
                        showToast(result.message || 'Failed to delete card.', 'error');
                        btn.disabled = false;
                        btn.textContent = 'Delete';
                    }
                } catch (err) {
                    showToast('Network error deleting card.', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Delete';
                }
            });
        });
    }

    paymentTab.innerHTML = `
        <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);">My Payment Methods</h4>
        
        <div class="cards-list" id="cardsContainer">
            <!-- Rendered dynamically -->
        </div>
        
        <h4 style="margin: 1.5rem 0 0.75rem; font-size: 1rem; font-weight: 700; color: var(--accent); border-top: 1px solid var(--border-glass); padding-top: 1rem;">Add New Payment Card</h4>
        <form id="addCardForm">
            <div class="edit-profile-field">
                <label for="card-holder">Cardholder Name</label>
                <input type="text" id="card-holder" placeholder="e.g. John Doe" required>
            </div>
            <div class="form-row" style="display: flex; gap: 1rem;">
                <div class="edit-profile-field" style="flex: 2;">
                    <label for="card-num">Card Number (16 Digits)</label>
                    <input type="text" id="card-num" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="19" required>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="card-expiry">Expiry Date (MM/YY)</label>
                    <input type="text" id="card-expiry" placeholder="MM/YY" maxlength="5" required>
                </div>
            </div>
            <button type="submit" class="edit-profile-save" style="margin-top: 0.5rem; width: 100%;">Add Card</button>
        </form>
    `;

    renderCards();

    // Auto format card number input with spacing
    const cardNumInput = paymentTab.querySelector('#card-num');
    cardNumInput.addEventListener('input', (e) => {
        let val = e.target.value.replace(/\D/g, '');
        let formatted = '';
        for (let i = 0; i < val.length; i++) {
            if (i > 0 && i % 4 === 0) formatted += '-';
            formatted += val[i];
        }
        e.target.value = formatted;
    });

    // Auto format card expiry date with slash
    const cardExpiryInput = paymentTab.querySelector('#card-expiry');
    cardExpiryInput.addEventListener('input', (e) => {
        let val = e.target.value.replace(/\D/g, '');
        if (val.length >= 2) {
            e.target.value = val.substring(0, 2) + '/' + val.substring(2, 4);
        } else {
            e.target.value = val;
        }
    });

    // Card form submission submit handler
    paymentTab.querySelector('#addCardForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const cardholder = paymentTab.querySelector('#card-holder').value.trim();
        const rawNum = cardNumInput.value.replace(/\D/g, '');
        const expiry = cardExpiryInput.value.trim();
        
        if (rawNum.length !== 16) {
            showToast('Please enter a valid 16-digit card number.', 'error');
            return;
        }
        if (!/^\d{2}\/\d{2}$/.test(expiry)) {
            showToast('Please enter expiry in MM/YY format.', 'error');
            return;
        }
        
        const saveBtn = paymentTab.querySelector('[type="submit"]');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Adding card...';
        
        // Mask card number for 3NF visual security (e.g. Card ending in XXXX)
        const masked = '•••• •••• •••• ' + rawNum.substring(12);
        
        try {
            const res = await fetch('ajax_user_profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: user.id,
                    action: 'add_payment_card',
                    cardholder_name: cardholder,
                    card_number: masked,
                    expiry_date: expiry
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                localCards.push({
                    id: data.card_id,
                    cardholder_name: cardholder,
                    card_number: masked,
                    expiry_date: expiry
                });
                showToast('Card saved successfully!', 'success');
                paymentTab.querySelector('#addCardForm').reset();
                renderCards();
            } else {
                showToast(data.message || 'Failed to save card.', 'error');
            }
        } catch (err) {
            showToast('Network error saving card.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Add Card';
        }
    });

    // ────────────────────────────────────────────────────────
    // TAB 5: BECOME A BUDDY / BUDDY SETTINGS
    // ────────────────────────────────────────────────────────
    const buddyTab = overlay.querySelector('#tab-buddy');
    
    // Retrieve Buddy Details if they are already registered
    const buddyProfile = dbData.buddy_profile || {};
    const buddyLanguages = dbData.buddy_languages || []; // array of IDs
    const buddySpecialties = dbData.buddy_specialties || []; // array of IDs
    const buddyGallery = dbData.buddy_gallery || []; // array of image objects {id, image_url}
    const buddyVerificationStatus = userData.verification_status || 'none';
    const buddyVerificationType = userData.verification_type || 'id';
    const buddyIdPhotoUrl = userData.id_photo_url || '';
    
    // Category titles map choice config
    const categoryTitlesMap = {
        casual: ['Casual Companion', 'Coffee & Chat Buddy', 'Local Tour Guide', 'Study & Homework Buddy'],
        event: ['Wedding Plus-One', 'Concert & Party Escort', 'Business Dinner Partner', 'Social Event Guest'],
        security: ['Personal Safety Escort', 'Executive Protection Specialist', 'Night-Out Bodyguard', 'Event Security Marshal'],
        arts: ['Photography Model', 'Art Collaboration Partner', 'Drawing/Painting Subject', 'Design Consultation Assistant'],
        listener: ['Active Listening Companion', 'Emotional Vent Session Partner', 'Silent Study Supporter', 'Compassionate Advisor'],
        ally: ['Pride Event Escort', 'LGBTQ+ Friendly Companion', 'Ally Representative', 'Safe Space Partner']
    };

    // Category options mapping select markup
    const getCategoryOptions = (cat) => {
        if (!cat || !categoryTitlesMap[cat]) return '<option value="" disabled selected>Please select category first</option>';
        return categoryTitlesMap[cat].map(t => `<option value="${t}" ${buddyProfile.professional_title === t ? 'selected' : ''}>${t}</option>`).join('');
    };

    // Render lookup checkboxes helpers
    const renderLanguagesChecklist = () => {
        return languagesLookup.map(l => {
            const isChecked = buddyLanguages.includes(Number(l.language_id));
            return `
                <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 500; color: var(--text-modal); cursor: pointer; margin-bottom: 0.35rem;">
                    <input type="checkbox" name="buddy_languages" value="${l.language_id}" ${isChecked ? 'checked' : ''} style="width: auto;">
                    <span>${escapeHtml(l.language_name)}</span>
                </label>
            `;
        }).join('');
    };

    const renderSpecialtiesChecklist = () => {
        return specialtiesLookup.map(s => {
            const isChecked = buddySpecialties.includes(Number(s.specialty_id));
            return `
                <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 500; color: var(--text-modal); cursor: pointer; margin-bottom: 0.35rem;">
                    <input type="checkbox" name="buddy_specialties" value="${s.specialty_id}" ${isChecked ? 'checked' : ''} style="width: auto;">
                    <span>${escapeHtml(s.specialty_name)}</span>
                </label>
            `;
        }).join('');
    };

    // Render verification status banner class
    let verificationBadgeClass = 'status-badge--progress';
    if (buddyVerificationStatus === 'verified') verificationBadgeClass = 'status-badge--completed';
    else if (buddyVerificationStatus === 'none') verificationBadgeClass = 'status-badge--progress';
    else if (buddyVerificationStatus === 'declined') verificationBadgeClass = 'status-badge--declined';

    // Base markup for Buddy form
    buddyTab.innerHTML = `
        <h4 style="margin: 0 0 1.25rem; font-size: 1.15rem; font-weight: 700; color: var(--accent);" id="buddyFormTitle">
            ${isBuddy ? 'Manage My Buddy Profile Settings' : 'Become an AnyBuddy Partner'}
        </h4>
        
        <form id="buddySettingsForm">
            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-display-name">Public Display Name</label>
                    <input type="text" id="buddy-display-name" value="${buddyProfile.display_name || (user.first_name + ' ' + user.last_name)}" required>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-category">Specialty Category</label>
                    <select id="buddy-category" required>
                        <option value="" disabled ${!buddyProfile.category ? 'selected' : ''}>Select category</option>
                        <option value="casual" ${buddyProfile.category === 'casual' ? 'selected' : ''}>Casual Hangout ☕</option>
                        <option value="event" ${buddyProfile.category === 'event' ? 'selected' : ''}>Event Plus-One 🎉</option>
                        <option value="security" ${buddyProfile.category === 'security' ? 'selected' : ''}>Bodyguard & Security 🛡️</option>
                        <option value="arts" ${buddyProfile.category === 'arts' ? 'selected' : ''}>Visual Arts 🎨</option>
                        <option value="listener" ${buddyProfile.category === 'listener' ? 'selected' : ''}>Active Listener 👂</option>
                        <option value="ally" ${buddyProfile.category === 'ally' ? 'selected' : ''}>LGBTQ+ Ally 🏳️‍🌈</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-title">Specialty Title Selection</label>
                    <select id="buddy-title" required ${!buddyProfile.category ? 'disabled' : ''}>
                        ${getCategoryOptions(buddyProfile.category)}
                    </select>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-rate">Hourly Rate (₱/hr)</label>
                    <input type="number" id="buddy-rate" value="${buddyProfile.hourly_rate || 150}" min="50" max="5000" step="10" required>
                </div>
            </div>
            
            <div class="form-row" style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-location">Cavite Municipality</label>
                    <select id="buddy-location" required>
                        <option value="" disabled ${!buddyProfile.location ? 'selected' : ''}>Select municipality</option>
                        <option value="Indang" ${buddyProfile.location === 'Indang' ? 'selected' : ''}>Indang (CvSU Main)</option>
                        <option value="Tanza" ${buddyProfile.location === 'Tanza' ? 'selected' : ''}>Tanza</option>
                        <option value="Trece Martires" ${buddyProfile.location === 'Trece Martires' ? 'selected' : ''}>Trece Martires</option>
                        <option value="General Trias" ${buddyProfile.location === 'General Trias' ? 'selected' : ''}>General Trias</option>
                        <option value="Naic" ${buddyProfile.location === 'Naic' ? 'selected' : ''}>Naic</option>
                        <option value="Silang" ${buddyProfile.location === 'Silang' ? 'selected' : ''}>Silang</option>
                        <option value="Tagaytay" ${buddyProfile.location === 'Tagaytay' ? 'selected' : ''}>Tagaytay</option>
                        <option value="Imus" ${buddyProfile.location === 'Imus' ? 'selected' : ''}>Imus</option>
                        <option value="Dasmariñas" ${buddyProfile.location === 'Dasmariñas' ? 'selected' : ''}>Dasmariñas</option>
                        <option value="Bacoor" ${buddyProfile.location === 'Bacoor' ? 'selected' : ''}>Bacoor</option>
                        <option value="Rosario" ${buddyProfile.location === 'Rosario' ? 'selected' : ''}>Rosario</option>
                    </select>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label for="buddy-availability">Availability Schedule</label>
                    <select id="buddy-availability" required>
                        <option value="" disabled ${!buddyProfile.availability ? 'selected' : ''}>Select availability</option>
                        <option value="Flexible (Mon-Sun, 8AM - 10PM)" ${buddyProfile.availability === 'Flexible (Mon-Sun, 8AM - 10PM)' ? 'selected' : ''}>Flexible (Mon-Sun, 8AM - 10PM)</option>
                        <option value="Weekdays (Mon-Fri, 8AM - 5PM)" ${buddyProfile.availability === 'Weekdays (Mon-Fri, 8AM - 5PM)' ? 'selected' : ''}>Weekdays (Mon-Fri, 8AM - 5PM)</option>
                        <option value="Weekends (Sat-Sun, 8AM - 10PM)" ${buddyProfile.availability === 'Weekends (Sat-Sun, 8AM - 10PM)' ? 'selected' : ''}>Weekends (Sat-Sun, 8AM - 10PM)</option>
                        <option value="Evenings Only (Mon-Sun, 6PM - 10PM)" ${buddyProfile.availability === 'Evenings Only (Mon-Sun, 6PM - 10PM)' ? 'selected' : ''}>Evenings Only (Mon-Sun, 6PM - 10PM)</option>
                        <option value="Part-Time (Flexible Days, 4 hours/day)" ${buddyProfile.availability === 'Part-Time (Flexible Days, 4 hours/day)' ? 'selected' : ''}>Part-Time (Flexible, 4h/day)</option>
                    </select>
                </div>
            </div>
            
            <div class="edit-profile-field">
                <label for="buddy-bio">Public Buddy Bio / Services Description</label>
                <textarea id="buddy-bio" rows="3" placeholder="Explain your services, hobbies, and why clients should book you..." required>${buddyProfile.bio || ''}</textarea>
            </div>
            
            <!-- Lookups Checkboxes rows -->
            <div style="display: flex; gap: 1rem; margin-bottom: 1rem; border-top: 1px solid var(--border-glass); padding-top: 1rem;">
                <div class="edit-profile-field" style="flex: 1;">
                    <label>Spoken Languages</label>
                    <div style="max-height: 120px; overflow-y: auto; padding: 0.5rem; border: 1px solid var(--border-modal); border-radius: 8px; background: var(--bg-modal-input);">
                        ${renderLanguagesChecklist()}
                    </div>
                </div>
                <div class="edit-profile-field" style="flex: 1;">
                    <label>Profile Keywords / Specialties</label>
                    <div style="max-height: 120px; overflow-y: auto; padding: 0.5rem; border: 1px solid var(--border-modal); border-radius: 8px; background: var(--bg-modal-input);">
                        ${renderSpecialtiesChecklist()}
                    </div>
                </div>
            </div>
            
            <!-- 3NF MULTIPLE GALLERY IMAGES ZONE -->
            <div style="margin-bottom: 1rem; border-top: 1px solid var(--border-glass); padding-top: 1rem;">
                <label style="font-weight: 700; font-size: 0.85rem; display: block; margin-bottom: 0.4rem;">Buddy Image Gallery</label>
                <div class="buddy-gallery-uploader-grid" id="galleryUploaderGrid">
                    <!-- Loaded dynamically: existing photos + new previews + add card -->
                </div>
                <input type="file" id="buddy-gallery-files" accept="image/*" multiple style="display: none;">
            </div>

            <!-- Verification section -->
            <div style="border-top: 1px solid var(--border-glass); padding-top: 1rem; margin-bottom: 1rem;">
                <h4 style="margin: 0 0 0.75rem; font-size: 1rem; font-weight: 700; color: var(--accent); display: flex; justify-content: space-between;">
                    <span>Document Verification</span>
                    <span class="status-badge ${verificationBadgeClass}" style="font-size: 0.7rem; padding: 0.15rem 0.45rem;">
                        ${buddyVerificationStatus.toUpperCase()}
                    </span>
                </h4>
                
                <div class="form-row" style="display: flex; gap: 1rem;">
                    <div class="edit-profile-field" style="flex: 1;">
                        <label for="buddy-verif-type">Document ID Type</label>
                        <select id="buddy-verif-type">
                            <option value="id" ${buddyVerificationType === 'id' ? 'selected' : ''}>Government ID Card</option>
                            <option value="student" ${buddyVerificationType === 'student' ? 'selected' : ''}>Student ID Card</option>
                            <option value="professional" ${buddyVerificationType === 'professional' ? 'selected' : ''}>Professional License</option>
                        </select>
                    </div>
                    <div class="edit-profile-field" style="flex: 1;">
                        <label>Verification Document Image</label>
                        <div class="image-upload-zone" id="buddy-verif-zone" style="cursor: pointer; padding: 0.5rem; text-align: center; border: 1px solid var(--border-modal); border-radius: 10px; background: var(--bg-modal-input); min-height: 42px;">
                            <input type="file" id="buddy-verif-file" accept="image/*" style="display: none;">
                            <span id="buddy-verif-label" style="font-size: 0.8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; padding-top: 0.35rem; color: var(--text-modal);">
                                ${buddyIdPhotoUrl ? '✓ Document Uploaded (Click to change)' : 'Select document photo'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="edit-profile-actions">
                <button type="submit" class="edit-profile-save">
                    ${isBuddy ? 'Save Buddy Settings' : 'Submit Buddy Application'}
                </button>
            </div>
        </form>
    `;

    // Category card choosing trigger
    const catSelect = buddyTab.querySelector('#buddy-category');
    const titleSelect = buddyTab.querySelector('#buddy-title');
    
    catSelect.addEventListener('change', () => {
        const val = catSelect.value;
        if (val) {
            titleSelect.innerHTML = getCategoryOptions(val);
            titleSelect.disabled = false;
        } else {
            titleSelect.innerHTML = '<option value="" disabled selected>Please select category first</option>';
            titleSelect.disabled = true;
        }
    });

    // Verification ID base64 image state
    let verifBase64 = null;
    let verifPhotoUrl = buddyIdPhotoUrl;
    
    const verifFile = buddyTab.querySelector('#buddy-verif-file');
    const verifZone = buddyTab.querySelector('#buddy-verif-zone');
    const verifLabel = buddyTab.querySelector('#buddy-verif-label');
    
    verifZone.addEventListener('click', () => verifFile.click());
    verifFile.addEventListener('change', () => {
        if (verifFile.files.length > 0) {
            const file = verifFile.files[0];
            verifLabel.textContent = file.name;
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = () => {
                verifBase64 = reader.result;
                verifPhotoUrl = '';
            };
        }
    });

    // Gallery state tracking
    let localGallery = [...buddyGallery]; // holding image objects {id, image_url}
    let galleryBase64Array = []; // newly selected base64 strings
    let deletedGalleryIds = []; // IDs of existing gallery images to delete

    const galleryFilesInput = buddyTab.querySelector('#buddy-gallery-files');
    const galleryUploaderGrid = buddyTab.querySelector('#galleryUploaderGrid');
    
    function renderGalleryGrid() {
        // Clear grid
        galleryUploaderGrid.innerHTML = '';
        
        // 1. Render existing gallery images
        localGallery.forEach(img => {
            if (deletedGalleryIds.includes(img.id)) return;
            
            const card = document.createElement('div');
            card.className = 'gallery-preview-card';
            card.innerHTML = `
                <img src="${img.image_url}" alt="Gallery photo">
                <button type="button" class="gallery-preview-delete-btn delete-existing-gallery" data-img-id="${img.id}">&times;</button>
            `;
            galleryUploaderGrid.appendChild(card);
        });
        
        // 2. Render newly selected base64 images
        galleryBase64Array.forEach((b64, idx) => {
            const card = document.createElement('div');
            card.className = 'gallery-preview-card';
            card.innerHTML = `
                <img src="${b64}" alt="New photo preview">
                <button type="button" class="gallery-preview-delete-btn delete-new-gallery" data-index="${idx}">&times;</button>
            `;
            galleryUploaderGrid.appendChild(card);
        });
        
        // 3. Render Add Card
        const addCard = document.createElement('div');
        addCard.className = 'gallery-add-card';
        addCard.innerHTML = `
            <span style="font-size: 1.5rem; font-weight: bold;">+</span>
            <span style="font-size: 0.75rem;">Add Photo</span>
        `;
        galleryUploaderGrid.appendChild(addCard);
        
        // Bind Add Card click
        addCard.addEventListener('click', () => {
            galleryFilesInput.click();
        });
        
        // Bind existing gallery deletes
        galleryUploaderGrid.querySelectorAll('.delete-existing-gallery').forEach(btn => {
            btn.addEventListener('click', () => {
                const imgId = Number(btn.dataset.imgId);
                deletedGalleryIds.push(imgId);
                renderGalleryGrid();
            });
        });
        
        // Bind new gallery deletes
        galleryUploaderGrid.querySelectorAll('.delete-new-gallery').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = Number(btn.dataset.index);
                galleryBase64Array.splice(idx, 1);
                renderGalleryGrid();
            });
        });
    }

    renderGalleryGrid();

    // Gallery file select input change handler
    galleryFilesInput.addEventListener('change', () => {
        const files = Array.from(galleryFilesInput.files);
        let filesLoaded = 0;
        
        files.forEach(file => {
            if (!file.type.startsWith('image/')) {
                showToast('Please select image files only.', 'error');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                showToast('Image size cannot exceed 5MB.', 'error');
                return;
            }
            
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = () => {
                galleryBase64Array.push(reader.result);
                filesLoaded++;
                if (filesLoaded === files.length) {
                    renderGalleryGrid();
                }
            };
        });
    });

    // Form submit save/register handler
    buddyTab.querySelector('#buddySettingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Gather languages checkbox values
        const checkedLangs = Array.from(buddyTab.querySelectorAll('input[name="buddy_languages"]:checked')).map(cb => Number(cb.value));
        // Gather specialties checkbox values
        const checkedSpecs = Array.from(buddyTab.querySelectorAll('input[name="buddy_specialties"]:checked')).map(cb => Number(cb.value));
        
        const saveBtn = buddyTab.querySelector('.edit-profile-save');
        saveBtn.disabled = true;
        saveBtn.textContent = isBuddy ? 'Saving settings...' : 'Submitting application...';
        
        const payload = {
            user_id: user.id,
            action: 'update_buddy_profile',
            is_registering: !isBuddy,
            display_name: buddyTab.querySelector('#buddy-display-name').value.trim(),
            category: catSelect.value,
            title: titleSelect.value,
            rate: Number(buddyTab.querySelector('#buddy-rate').value),
            location: buddyTab.querySelector('#buddy-location').value,
            availability: buddyTab.querySelector('#buddy-availability').value,
            bio: buddyTab.querySelector('#buddy-bio').value.trim(),
            verification_type: buddyTab.querySelector('#buddy-verif-type').value,
            id_photo_url: verifPhotoUrl,
            id_photo_image_data: verifBase64,
            languages: checkedLangs,
            specialties: checkedSpecs,
            deleted_gallery_ids: deletedGalleryIds,
            gallery_images_data: galleryBase64Array
        };
        
        try {
            const res = await fetch('ajax_user_profile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.status === 'success') {
                showToast(data.message, 'success');
                
                // Update local storage role
                user.role = 'buddy';
                user.buddy_profile_id = data.profile_id;
                persistAuthState(user);
                
                // Close modal and refresh nav/homepage welcome
                setTimeout(() => {
                    closeModal();
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message || 'Failed to save buddy details.', 'error');
            }
        } catch (err) {
            showToast('Network error saving buddy settings.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = isBuddy ? 'Save Buddy Settings' : 'Submit Buddy Application';
        }
    });
}

/* ─────────────────────────────────────────────────────────────
   SECTION 14 ── Report User Page Loader (report.html)
   ───────────────────────────────────────────────────────────── */
(async function initReportPage() {
    const reportPage = document.querySelector('.report-page');
    if (!reportPage) return;
    
    // 1. Guest protection check
    const user = getCurrentUser();
    if (!user) {
        localStorage.setItem('ab_trigger_login', 'true');
        window.location.href = 'homepage.html';
        return;
    }
    
    const reportForm = document.querySelector('.report-form');
    if (!reportForm) return;

    const params = new URLSearchParams(window.location.search);
    const name = params.get('user') || 'User';
    const reportedId = parseInt(params.get('reported_id') || '0', 10);

    const nameEl = document.getElementById('reported-name');
    if (nameEl) {
        nameEl.textContent = name;
    }

    // Hijack cancel button to go back
    const cancelBtn = reportForm.querySelector('.btn-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.history.back();
        });
    }

    reportForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (reportedId <= 0) {
            showToast('Invalid reported user details.', 'error');
            return;
        }

        const reasonSelect = reportForm.querySelector('#reason');
        const descTextarea = reportForm.querySelector('#description');
        const reason = reasonSelect ? reasonSelect.value : '';
        const description = descTextarea ? descTextarea.value : '';

        if (!reason) {
            showToast('Please select a reason.', 'error');
            return;
        }
        if (!description || description.trim().length < 10) {
            showToast('Please provide a description of at least 10 characters.', 'error');
            return;
        }

        const submitBtn = reportForm.querySelector('.btn-submit');
        const origText = submitBtn ? submitBtn.textContent : 'Submit Report';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        }

        try {
            const res = await fetch('ajax_report.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    reporter_id: user.id,
                    reported_id: reportedId,
                    reason: reason,
                    description: description
                })
            });

            const data = await res.json();
            if (data.status === 'success') {
                showToast(data.message, 'success');
                setTimeout(() => {
                    window.location.href = 'marketplace.html';
                }, 2000);
            } else {
                showToast(data.message || 'Failed to submit report.', 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = origText;
                }
            }
        } catch (err) {
            console.error(err);
            showToast('A network error occurred.', 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = origText;
            }
        }
    });
})();

function initScrollRevealAndCounters() {
    const reveals = document.querySelectorAll('.scroll-reveal');
    if (reveals.length === 0) return;
    
    const countUp = (el) => {
        const target = parseInt(el.getAttribute('data-target') || '0', 10);
        if (target <= 0) return;
        
        let start = 0;
        const duration = 2000; // 2 seconds
        const startTime = performance.now();
        
        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            const easeOutCubic = 1 - Math.pow(1 - progress, 3);
            const currentVal = Math.floor(easeOutCubic * target);
            
            if (target >= 1000) {
                el.textContent = currentVal.toLocaleString('en-US') + '+';
            } else if (target === 6) {
                el.textContent = currentVal;
            } else {
                el.textContent = currentVal + '+';
            }
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                if (target >= 1000) {
                    el.textContent = target.toLocaleString('en-US') + '+';
                } else if (target === 6) {
                    el.textContent = target;
                } else {
                    el.textContent = target + '+';
                }
            }
        };
        
        requestAnimationFrame(animate);
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                
                const stats = entry.target.querySelectorAll('.stat-number');
                stats.forEach(stat => {
                    if (!stat.dataset.animated) {
                        stat.dataset.animated = 'true';
                        countUp(stat);
                    }
                });
                
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.15
    });
    
    reveals.forEach(el => observer.observe(el));
}

// Run scroll reveal on load
if (document.documentElement) {
    const checkChatGuard = () => {
        const path = window.location.pathname;
        if (path.includes('chat.html') && !getCurrentUser()) {
            const dest = path.substring(path.lastIndexOf('/') + 1) + window.location.search;
            window.location.href = `login.html?redirect=${encodeURIComponent(dest)}`;
        }
    };
    checkChatGuard();
}

async function initChatPage() {
    const chatPage = document.querySelector('.chat-page');
    if (!chatPage) return;
    
    const user = getCurrentUser();
    if (!user) {
        showToast('You must be logged in to view chat.', 'error');
        setTimeout(() => { window.location.href = 'login.html'; }, 1500);
        return;
    }
    
    const layoutContainer = document.getElementById('chatLayoutContainer');
    const threadsList = document.getElementById('threadsList');
    
    const chatEmptyState = document.getElementById('chatEmptyState');
    const chatPanelContent = document.getElementById('chatPanelContent');
    const messagesArea = document.getElementById('messagesArea');
    const chatForm = document.getElementById('chatInputForm');
    const chatInput = document.getElementById('chatInputMessage');
    
    const activeChatAvatar = document.getElementById('activeChatAvatar');
    const activeChatName = document.getElementById('activeChatName');
    const activeChatStatus = document.getElementById('activeChatStatus');
    
    const profileSidebar = document.getElementById('profileSidebar');
    const activeProfileTitle = document.getElementById('activeProfileTitle');
    const activeProfileAvatar = document.getElementById('activeProfileAvatar');
    const activeProfileName = document.getElementById('activeProfileName');
    const activeProfileRole = document.getElementById('activeProfileRole');
    const activeProfileLocation = document.getElementById('activeProfileLocation');
    const activeProfileBookingTime = document.getElementById('activeProfileBookingTime');
    const activeProfileReportLink = document.getElementById('activeProfileReportLink');
    const activeProfileViewBtn = document.getElementById('activeProfileViewBtn');
    
    const backToInboxBtn = document.getElementById('backToInboxBtn');
    
    let activeBookingId = null;
    let localMessages = [];
    let isFetching = false;
    let pollInterval = null;
    
    // Help escape HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Scroll chat area to bottom
    function scrollToBottom() {
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }
    }
    
    // Fetch and render thread lists
    async function loadThreadsList() {
        try {
            const res = await fetch(`ajax_chat.php?user_id=${user.id}`);
            const data = await res.json();
            if (data.status !== 'success') {
                threadsList.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">Failed to load conversations.</div>`;
                return;
            }
            
            const chats = data.chats || [];
            if (chats.length === 0) {
                threadsList.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">No active bookings or chats found.</div>`;
                return;
            }
            
            threadsList.innerHTML = chats.map(c => {
                const isActive = activeBookingId === Number(c.booking_id);
                return `
                    <div class="thread-item ${isActive ? 'active' : ''}" data-booking-id="${c.booking_id}">
                        <div class="thread-avatar-wrap">
                            <img src="${c.other_avatar || 'images/user-light.png'}" alt="${escapeHtml(c.other_name)}">
                            <span class="status-dot"></span>
                        </div>
                        <div class="thread-details">
                            <div class="thread-header-row">
                                <h4 class="thread-name">${escapeHtml(c.other_name)}</h4>
                                <span class="thread-time">${escapeHtml(c.last_message_time)}</span>
                            </div>
                            <p class="thread-last-msg">${escapeHtml(c.last_message)}</p>
                        </div>
                    </div>
                `;
            }).join('');
            
            // Bind click to each thread item
            threadsList.querySelectorAll('.thread-item').forEach(item => {
                item.addEventListener('click', () => {
                    const bId = Number(item.dataset.bookingId);
                    selectThread(bId);
                });
            });
            
        } catch (err) {
            console.error("Error fetching threads:", err);
            threadsList.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-secondary);">Error loading inbox.</div>`;
        }
    }
    
    // Select a conversation thread
    async function selectThread(bookingId) {
        if (pollInterval) clearInterval(pollInterval);
        
        activeBookingId = bookingId;
        history.pushState(null, '', `chat.html?booking_id=${bookingId}`);
        
        // Update sidebar list items active styles
        threadsList.querySelectorAll('.thread-item').forEach(item => {
            const isTarget = Number(item.dataset.bookingId) === bookingId;
            item.classList.toggle('active', isTarget);
        });
        
        // Show active chat panel
        if (chatEmptyState) chatEmptyState.style.display = 'none';
        if (chatPanelContent) chatPanelContent.style.display = 'flex';
        
        // Mobile visibility toggle
        if (layoutContainer) {
            layoutContainer.classList.add('active-chat');
        }
        
        localMessages = [];
        messagesArea.innerHTML = `<div style="padding: 3rem; text-align: center; color: var(--text-muted);">Loading conversation history...</div>`;
        
        await fetchChatData(true);
        
        // Start polling loop for new messages
        pollInterval = setInterval(() => {
            fetchChatData(false);
        }, 3000);
    }
    
    // Fetch conversation data
    async function fetchChatData(isInitial = false) {
        if (!activeBookingId) return;
        if (isFetching) return;
        isFetching = true;
        
        try {
            const res = await fetch(`ajax_chat.php?booking_id=${activeBookingId}&user_id=${user.id}`);
            const data = await res.json();
            isFetching = false;
            
            if (data.status !== 'success') {
                if (isInitial) {
                    showToast('Failed to load conversation history.', 'error');
                }
                return;
            }
            
            const booking = data.booking;
            const messages = data.messages || [];
            
            if (isInitial) {
                // Determine symmetric roles
                const isClient = (user.id === booking.client_id);
                const otherName = isClient ? booking.buddy_name : booking.client_name;
                const otherAvatar = isClient ? booking.buddy_avatar : booking.client_avatar;
                const otherTitle = isClient ? booking.buddy_title : 'Client Partner';
                const otherLocation = isClient ? booking.buddy_location : 'N/A';
                
                // Update conversation details
                document.title = `Chat with ${otherName} — AnyBuddy`;
                if (activeChatName) activeChatName.textContent = otherName;
                if (activeChatAvatar) {
                    activeChatAvatar.src = otherAvatar;
                    activeChatAvatar.alt = otherName;
                }
                if (activeChatStatus) {
                    activeChatStatus.textContent = booking.status === 'Completed' ? 'Session completed' : 'Active now';
                }
                
                // Update profile sidebar
                if (profileSidebar) profileSidebar.style.display = 'block';
                if (activeProfileTitle) activeProfileTitle.textContent = `${otherName}'s Profile`;
                if (activeProfileAvatar) {
                    activeProfileAvatar.src = otherAvatar;
                    activeProfileAvatar.alt = otherName;
                }
                if (activeProfileName) activeProfileName.textContent = otherName;
                if (activeProfileRole) activeProfileRole.textContent = otherTitle;
                if (activeProfileLocation) activeProfileLocation.textContent = otherLocation;
                if (activeProfileBookingTime) {
                    activeProfileBookingTime.textContent = `${booking.booking_date_fmt} at ${booking.start_time_fmt}`;
                }
                if (activeProfileReportLink) {
                    activeProfileReportLink.href = `report.html?user=${encodeURIComponent(otherName)}&booking_id=${booking.id}`;
                }
                if (activeProfileViewBtn) {
                    if (isClient) {
                        activeProfileViewBtn.href = `profile.html?id=${booking.buddy_profile_id}`;
                        activeProfileViewBtn.style.display = 'block';
                    } else {
                        activeProfileViewBtn.style.display = 'none';
                    }
                }
            }
            
            if (messages.length !== localMessages.length) {
                localMessages = messages;
                
                // Render message history bubbles
                const divider = `<div class="date-divider"><span>Booking Chat Session</span></div>`;
                const bubblesHtml = localMessages.map(msg => {
                    const isOutgoing = (msg.sender_id === user.id);
                    return `
                        <div class="message ${isOutgoing ? 'message--outgoing' : 'message--incoming'}" data-msg-id="${msg.id}">
                            <div class="bubble">${escapeHtml(msg.message_text)}</div>
                            <time class="message-time">${escapeHtml(msg.created_at_fmt)}</time>
                        </div>
                    `;
                }).join('');
                
                messagesArea.innerHTML = divider + bubblesHtml;
                scrollToBottom();
                
                // Update sidebar list snippet
                loadThreadsList();
            }
            
        } catch (err) {
            isFetching = false;
            console.error("Error loading chat data:", err);
        }
    }
    
    // Back to inbox for mobile layout
    if (backToInboxBtn) {
        backToInboxBtn.addEventListener('click', () => {
            if (pollInterval) clearInterval(pollInterval);
            activeBookingId = null;
            history.pushState(null, '', 'chat.html');
            
            if (layoutContainer) {
                layoutContainer.classList.remove('active-chat');
            }
            
            threadsList.querySelectorAll('.thread-item').forEach(item => {
                item.classList.remove('active');
            });
            
            if (chatEmptyState) chatEmptyState.style.display = 'flex';
            if (chatPanelContent) chatPanelContent.style.display = 'none';
            if (profileSidebar) profileSidebar.style.display = 'none';
            
            document.title = 'Chat — AnyBuddy';
            loadThreadsList();
        });
    }
    
    // Send message form submission handler
    if (chatForm && chatInput) {
        chatForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const text = chatInput.value.trim();
            if (text === '' || !activeBookingId) return;
            
            chatInput.value = '';
            
            try {
                const res = await fetch('ajax_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        booking_id: Number(activeBookingId),
                        sender_id: user.id,
                        message_text: text
                    })
                });
                
                const result = await res.json();
                if (result.status === 'success') {
                    localMessages.push(result.message);
                    
                    const newBubble = document.createElement('div');
                    newBubble.className = 'message message--outgoing';
                    newBubble.dataset.msgId = result.message.id;
                    newBubble.innerHTML = `
                        <div class="bubble">${escapeHtml(result.message.message_text)}</div>
                        <time class="message-time">${escapeHtml(result.message.created_at_fmt)}</time>
                    `;
                    messagesArea.appendChild(newBubble);
                    scrollToBottom();
                    
                    // Live reload list snippet
                    loadThreadsList();
                } else {
                    showToast(result.message || 'Failed to send message.', 'error');
                    chatInput.value = text;
                }
            } catch (err) {
                showToast('Network error — failed to send message.', 'error');
                chatInput.value = text;
            }
        });
    }
    
    // Initial thread lists load
    await loadThreadsList();
    
    // Auto-select booking ID if provided in URL parameters
    const params = new URLSearchParams(window.location.search);
    const urlBookingId = params.get('booking_id');
    if (urlBookingId) {
        selectThread(Number(urlBookingId));
    }
    
    // Reset/clear poll loop on unload
    window.addEventListener('beforeunload', () => {
        if (pollInterval) clearInterval(pollInterval);
    });
}

async function initAdminDashboard() {
    const adminMain = document.querySelector('.admin-main');
    if (!adminMain) return;

    const user = getCurrentUser();
    if (!user || user.role !== 'admin') {
        showToast('Unauthorized access. Redirecting...', 'error');
        setTimeout(() => { window.location.href = 'homepage.html'; }, 1500);
        return;
    }

    const statTotalUsers = document.getElementById('statTotalUsers');
    const statTotalBookings = document.getElementById('statTotalBookings');
    const statTotalRevenue = document.getElementById('statTotalRevenue');
    const statPendingVerifications = document.getElementById('statPendingVerifications');
    const statActiveReports = document.getElementById('statActiveReports');

    const pendingVerifCount = document.getElementById('pendingVerifCount');
    const verificationsList = document.getElementById('verificationsList');

    const activeReportsCountBadge = document.getElementById('activeReportsCountBadge');
    const reportsList = document.getElementById('reportsList');

    const docPreviewModal = document.getElementById('docPreviewModal');
    const closeDocModalBtn = document.getElementById('closeDocModalBtn');
    const docDetailsLabel = document.getElementById('docDetailsLabel');
    const previewDocImage = document.getElementById('previewDocImage');
    const docModalFooterActions = document.getElementById('docModalFooterActions');

    // CRUD Panel Elements
    const subnavButtons = document.querySelectorAll('.subnav-btn');
    const adminPanels = document.querySelectorAll('.admin-panel');
    const crudFormModal = document.getElementById('crudFormModal');
    const closeCrudModalBtn = document.getElementById('closeCrudModalBtn');
    const btnCancelCrud = document.getElementById('btnCancelCrud');
    const crudForm = document.getElementById('crudForm');
    const crudModalTitle = document.getElementById('crudModalTitle');
    const crudModalFields = document.getElementById('crudModalFields');

    // CRUD Memory Lists for Search Filtering
    let rawUsersList = [];
    let rawBuddiesList = [];
    let rawBookingsList = [];
    let rawVouchersList = [];
    let rawAuditLogsList = [];
    let rawChatThreadsList = [];
    let lookupsData = null;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // --- TAB TOGGLE CONTROLLER ---
    subnavButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            subnavButtons.forEach(b => b.classList.remove('active'));
            adminPanels.forEach(p => {
                p.classList.remove('active');
                p.style.display = 'none';
            });

            btn.classList.add('active');
            const targetPanelId = `panel-${btn.dataset.panel}`;
            const targetPanel = document.getElementById(targetPanelId);
            if (targetPanel) {
                targetPanel.classList.add('active');
                targetPanel.style.display = 'block';
            }

            // Load panel data dynamically
            if (btn.dataset.panel === 'moderation') loadDashboardData();
            else if (btn.dataset.panel === 'users') loadUsersCrud();
            else if (btn.dataset.panel === 'buddies') loadBuddiesCrud();
            else if (btn.dataset.panel === 'bookings') loadBookingsCrud();
            else if (btn.dataset.panel === 'vouchers') loadVouchersCrud();
            else if (btn.dataset.panel === 'logs') loadAuditLogsCrud();
            else if (btn.dataset.panel === 'chats') loadChatThreadsCrud();
            else if (btn.dataset.panel === 'settings') loadSystemSettings();
        });
    });

    // --- 1. MODERATION HUB DATA LOADER ---
    async function loadDashboardData() {
        try {
            const res = await fetch(`ajax_admin.php?user_id=${user.id}`);
            const data = await res.json();

            if (data.status !== 'success') {
                showToast(data.message || 'Failed to load admin stats.', 'error');
                return;
            }

            // Populate stats
            const stats = data.stats || {};
            if (statTotalUsers) statTotalUsers.textContent = stats.total_users ?? '0';
            if (statTotalBookings) statTotalBookings.textContent = stats.total_bookings ?? '0';
            if (statTotalRevenue) statTotalRevenue.textContent = stats.total_revenue_fmt ?? '₱0.00';
            if (statPendingVerifications) statPendingVerifications.textContent = stats.pending_verifications ?? '0';
            if (statActiveReports) statActiveReports.textContent = stats.active_reports ?? '0';

            if (pendingVerifCount) pendingVerifCount.textContent = stats.pending_verifications ?? '0';
            if (activeReportsCountBadge) activeReportsCountBadge.textContent = stats.active_reports ?? '0';

            // Populate verifications
            const pendingVerifs = data.pending_verifications || [];
            if (verificationsList) {
                if (pendingVerifs.length === 0) {
                    verificationsList.innerHTML = `
                        <div class="empty-state" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                            No pending verifications.
                        </div>
                    `;
                } else {
                    verificationsList.innerHTML = pendingVerifs.map(v => {
                        return `
                            <div class="admin-item-card" style="padding: 1rem; border: 1px solid var(--border-glass); border-radius: 12px; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.02);">
                                <div>
                                    <h4 style="margin: 0 0 0.25rem 0; font-weight: 700; color: var(--text-primary);">${escapeHtml(v.display_name)}</h4>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">${escapeHtml(v.buddy_email)} • Type: ${escapeHtml(v.verification_type)}</span>
                                </div>
                                <button type="button" class="inspect-verif-btn admin-btn admin-btn-primary admin-btn-sm" data-id="${v.buddy_profile_id}" data-name="${escapeHtml(v.display_name)}" data-url="${escapeHtml(v.id_photo_url)}">Inspect</button>
                            </div>
                        `;
                    }).join('');

                    // Bind Inspect Buttons
                    verificationsList.querySelectorAll('.inspect-verif-btn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const buddyProfileId = this.dataset.id;
                            const name = this.dataset.name;
                            const url = this.dataset.url;

                            if (docDetailsLabel) docDetailsLabel.textContent = `Buddy: ${name}`;
                            if (previewDocImage) {
                                previewDocImage.src = url || 'images/AnyBuddy LOGO.png';
                                previewDocImage.onerror = function() { this.src = 'images/AnyBuddy LOGO.png'; };
                            }

                            if (docModalFooterActions) {
                                docModalFooterActions.innerHTML = `
                                    <button type="button" class="admin-btn admin-btn-success btn-approve-doc" data-id="${buddyProfileId}">Approve</button>
                                    <button type="button" class="admin-btn admin-btn-danger btn-reject-doc" data-id="${buddyProfileId}" style="margin-left: 0.5rem;">Reject</button>
                                `;

                                // Bind modal approve/reject
                                docModalFooterActions.querySelector('.btn-approve-doc').addEventListener('click', async function() {
                                    await handleVerificationAction(buddyProfileId, 'approve_verification');
                                    closeDocModal();
                                });

                                docModalFooterActions.querySelector('.btn-reject-doc').addEventListener('click', async function() {
                                    await handleVerificationAction(buddyProfileId, 'reject_verification');
                                    closeDocModal();
                                });
                            }

                            if (docPreviewModal) docPreviewModal.style.display = 'flex';
                        });
                    });
                }
            }

            // Populate reports
            const reports = data.reports || [];
            if (reportsList) {
                const activeReports = reports.filter(r => r.status === 'pending');
                if (activeReports.length === 0) {
                    reportsList.innerHTML = `
                        <div class="empty-state" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                            No active safety reports.
                        </div>
                    `;
                } else {
                    reportsList.innerHTML = activeReports.map(r => {
                        return `
                            <div class="admin-item-card" style="padding: 1rem; border: 1px solid var(--border-glass); border-radius: 12px; margin-bottom: 0.75rem; background: rgba(255, 255, 255, 0.02);">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                    <div>
                                        <h4 style="margin: 0 0 0.1rem 0; font-weight: 700; color: var(--text-primary);">Ticket #${r.report_id}</h4>
                                        <span style="font-size: 0.8rem; color: var(--text-muted);">Reporter: ${escapeHtml(r.reporter_first)} ${escapeHtml(r.reporter_last)} (${escapeHtml(r.reporter_email)})</span>
                                    </div>
                                    <span class="badge badge-warning" style="font-size: 0.75rem; padding: 0.2rem 0.5rem;">${escapeHtml(r.reason)}</span>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--text-secondary); background: rgba(0,0,0,0.15); padding: 0.75rem; border-radius: 8px; margin-bottom: 0.75rem;">
                                    <strong>Reported User:</strong> ${escapeHtml(r.reported_first)} ${escapeHtml(r.reported_last)} (${escapeHtml(r.reported_email)})<br>
                                    <strong>Details:</strong> ${escapeHtml(r.description)}
                                </div>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <button type="button" class="admin-btn admin-btn-success admin-btn-sm btn-resolve-report" data-id="${r.report_id}">Resolve</button>
                                    <button type="button" class="admin-btn admin-btn-warning admin-btn-sm btn-suspend-report" data-id="${r.report_id}">Suspend User</button>
                                    <button type="button" class="admin-btn admin-btn-danger admin-btn-sm btn-ban-report" data-id="${r.report_id}">Ban User</button>
                                    <button type="button" class="admin-btn admin-btn-secondary admin-btn-sm btn-dismiss-report" data-id="${r.report_id}">Dismiss</button>
                                </div>
                            </div>
                        `;
                    }).join('');

                    // Bind reports buttons
                    reportsList.querySelectorAll('.btn-resolve-report').forEach(btn => {
                        btn.addEventListener('click', async function() {
                            const reportId = this.dataset.id;
                            await handleReportAction(reportId, 'resolve_report');
                        });
                    });

                    reportsList.querySelectorAll('.btn-suspend-report').forEach(btn => {
                        btn.addEventListener('click', async function() {
                            const reportId = this.dataset.id;
                            if (confirm(`Are you sure you want to suspend this reported user and resolve Ticket #${reportId}?`)) {
                                await handleReportAction(reportId, 'suspend_reported_user');
                            }
                        });
                    });

                    reportsList.querySelectorAll('.btn-ban-report').forEach(btn => {
                        btn.addEventListener('click', async function() {
                            const reportId = this.dataset.id;
                            if (confirm(`Are you sure you want to PERMANENTLY BAN this reported user and resolve Ticket #${reportId}?`)) {
                                await handleReportAction(reportId, 'ban_reported_user');
                            }
                        });
                    });

                    reportsList.querySelectorAll('.btn-dismiss-report').forEach(btn => {
                        btn.addEventListener('click', async function() {
                            const reportId = this.dataset.id;
                            await handleReportAction(reportId, 'dismiss_report');
                        });
                    });
                }
            }

        } catch (err) {
            console.error('Error loading admin dashboard:', err);
            showToast('Network error loading admin stats.', 'error');
        }
    }

    async function handleVerificationAction(buddyProfileId, action) {
        try {
            const res = await fetch('ajax_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: user.id,
                    buddy_profile_id: parseInt(buddyProfileId, 10),
                    action: action
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                showToast(result.message || 'Verification status updated.', 'success');
                loadDashboardData();
            } else {
                showToast(result.message || 'Failed to update verification status.', 'error');
            }
        } catch (err) {
            showToast('Network error.', 'error');
        }
    }

    async function handleReportAction(reportId, action) {
        try {
            const res = await fetch('ajax_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: user.id,
                    report_id: parseInt(reportId, 10),
                    action: action
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                showToast(result.message || 'Report ticket updated.', 'success');
                loadDashboardData();
            } else {
                showToast(result.message || 'Failed to update report ticket.', 'error');
            }
        } catch (err) {
            showToast('Network error.', 'error');
        }
    }

    function closeDocModal() {
        if (docPreviewModal) docPreviewModal.style.display = 'none';
    }

    if (closeDocModalBtn) {
        closeDocModalBtn.addEventListener('click', closeDocModal);
    }

    window.addEventListener('click', (e) => {
        if (e.target === docPreviewModal) {
            closeDocModal();
        }
    });

    // --- FORM LOOKUPS LOADER ---
    async function fetchFormLookups() {
        if (lookupsData) return;
        try {
            const res = await fetch(`ajax_admin.php?action=get_form_lookups&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                lookupsData = data;
            }
        } catch (err) {
            console.error('Error fetching form lookups:', err);
        }
    }

    // --- 2. USERS CRUD CONTROLLER ---
    async function loadUsersCrud() {
        const body = document.getElementById('crudUsersTableBody');
        body.innerHTML = '<tr><td colspan="8" class="loading-state">Loading users...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_users&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawUsersList = data.users || [];
                renderUsersTable(rawUsersList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="8" class="loading-state" style="color: red;">Error loading users list.</td></tr>';
        }
    }

    function renderUsersTable(list) {
        const body = document.getElementById('crudUsersTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="8" class="loading-state">No users found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(u => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const fallbackAvatar = isDark ? 'images/user-dark.png' : 'images/user-light.png';
            const avatar = (u.profile_photo && u.profile_photo.trim() !== '' && u.profile_photo !== 'images/user-light.png')
                ? u.profile_photo
                : fallbackAvatar;
            
            // Map status to badge style
            let statusBadgeClass = 'badge-success';
            if (u.status === 'suspended') statusBadgeClass = 'badge-warning';
            if (u.status === 'banned') statusBadgeClass = 'badge-danger';

            return `
                <tr>
                    <td><strong>${u.user_id}</strong></td>
                    <td><img class="avatar-thumbnail" src="${escapeHtml(avatar)}" onerror="this.src='${fallbackAvatar}'"></td>
                    <td>${escapeHtml(u.first_name)} ${escapeHtml(u.last_name)}</td>
                    <td>${escapeHtml(u.email)}</td>
                    <td><span class="badge ${u.role === 'admin' ? 'badge-warning' : 'badge-info'}">${u.role.toUpperCase()}</span></td>
                    <td><span class="badge ${statusBadgeClass}" title="${u.status_reason ? escapeHtml(u.status_reason) : ''}">${u.status.toUpperCase()}</span></td>
                    <td>${escapeHtml(u.pronouns || 'N/A')}</td>
                    <td>
                        <div class="action-btn-group">
                            <button type="button" class="action-icon-btn btn-edit" data-id="${u.user_id}" title="Edit User">✏️</button>
                            <button type="button" class="action-icon-btn btn-delete" data-id="${u.user_id}" title="Delete User">🗑️</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        // Bind Actions
        body.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetUser = rawUsersList.find(u => u.user_id === Number(btn.dataset.id));
                openCrudModal('users', 'edit', targetUser);
            });
        });
        body.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', () => {
                deleteRecord('users', Number(btn.dataset.id));
            });
        });
    }

    // Users Search Filter
    const searchUsersInput = document.getElementById('searchUsers');
    if (searchUsersInput) {
        searchUsersInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawUsersList.filter(u => 
                u.first_name.toLowerCase().includes(query) || 
                u.last_name.toLowerCase().includes(query) || 
                u.email.toLowerCase().includes(query)
            );
            renderUsersTable(filtered);
        });
    }

    // --- 3. BUDDIES CRUD CONTROLLER ---
    async function loadBuddiesCrud() {
        const body = document.getElementById('crudBuddiesTableBody');
        body.innerHTML = '<tr><td colspan="9" class="loading-state">Loading buddies...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_buddies&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawBuddiesList = data.buddies || [];
                renderBuddiesTable(rawBuddiesList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="9" class="loading-state" style="color: red;">Error loading buddy profiles.</td></tr>';
        }
    }

    function renderBuddiesTable(list) {
        const body = document.getElementById('crudBuddiesTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="9" class="loading-state">No buddy profiles found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(b => {
            return `
                <tr>
                    <td><strong>${b.profile_id}</strong></td>
                    <td>${escapeHtml(b.display_name)}<br><small style="color: var(--text-muted);">${escapeHtml(b.email)}</small></td>
                    <td>${escapeHtml(b.professional_title)}</td>
                    <td><span class="badge badge-info">${escapeHtml(b.category.toUpperCase())}</span></td>
                    <td>₱${b.hourly_rate}/hr</td>
                    <td>${escapeHtml(b.location)}</td>
                    <td>${escapeHtml(b.availability)}</td>
                    <td><span class="badge ${b.is_available == 1 ? 'badge-success' : 'badge-warning'}">${b.is_available == 1 ? 'ACTIVE' : 'AWAY'}</span></td>
                    <td>
                        <div class="action-btn-group">
                            <button type="button" class="action-icon-btn btn-edit" data-id="${b.profile_id}" title="Edit Buddy Profile">✏️</button>
                            <button type="button" class="action-icon-btn btn-delete" data-id="${b.profile_id}" title="Delete Buddy Profile">🗑️</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        // Bind Actions
        body.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetBuddy = rawBuddiesList.find(b => b.profile_id === Number(btn.dataset.id));
                openCrudModal('buddies', 'edit', targetBuddy);
            });
        });
        body.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', () => {
                deleteRecord('buddies', Number(btn.dataset.id));
            });
        });
    }

    // Buddies Search Filter
    const searchBuddiesInput = document.getElementById('searchBuddies');
    if (searchBuddiesInput) {
        searchBuddiesInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawBuddiesList.filter(b => 
                b.display_name.toLowerCase().includes(query) || 
                b.professional_title.toLowerCase().includes(query) || 
                b.category.toLowerCase().includes(query) ||
                b.location.toLowerCase().includes(query)
            );
            renderBuddiesTable(filtered);
        });
    }

    // --- 4. BOOKINGS CRUD CONTROLLER ---
    async function loadBookingsCrud() {
        const body = document.getElementById('crudBookingsTableBody');
        body.innerHTML = '<tr><td colspan="9" class="loading-state">Loading bookings...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_bookings&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawBookingsList = data.bookings || [];
                renderBookingsTable(rawBookingsList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="9" class="loading-state" style="color: red;">Error loading bookings.</td></tr>';
        }
    }

    function renderBookingsTable(list) {
        const body = document.getElementById('crudBookingsTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="9" class="loading-state">No bookings found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(b => {
            let statusClass = 'status-badge--progress';
            if (b.status === 'Completed') statusClass = 'status-badge--completed';
            else if (b.status === 'Cancelled' || b.status === 'Declined') statusClass = 'status-badge--declined';

            let actionButtonsHtml = '<span style="color: var(--text-muted); font-size: 0.85rem;">Closed</span>';
            if (b.status === 'Requested' || b.status === 'Accepted' || b.status === 'Verification') {
                actionButtonsHtml = `
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="admin-btn admin-btn-success admin-btn-sm btn-force-complete" data-id="${b.booking_id}">Complete</button>
                        <button type="button" class="admin-btn admin-btn-danger admin-btn-sm btn-cancel-refund" data-id="${b.booking_id}">Cancel & Refund</button>
                    </div>
                `;
            }

            return `
                <tr>
                    <td><strong>${b.booking_id}</strong></td>
                    <td>${escapeHtml(b.client_first_name)} ${escapeHtml(b.client_last_name)}</td>
                    <td>${escapeHtml(b.buddy_name)}</td>
                    <td>${escapeHtml(b.booking_date)}<br><small style="color: var(--text-muted);">${escapeHtml(b.start_time.substring(0, 5))}</small></td>
                    <td>${b.hours_duration}h</td>
                    <td>₱${b.total_price}</td>
                    <td>${escapeHtml(b.payment_method)}</td>
                    <td><span class="status-badge ${statusClass}" style="font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 6px;">${b.status}</span></td>
                    <td>${actionButtonsHtml}</td>
                </tr>
            `;
        }).join('');

        // Bind Actions
        body.querySelectorAll('.btn-force-complete').forEach(btn => {
            btn.addEventListener('click', async () => {
                const bookingId = Number(btn.dataset.id);
                if (!confirm(`Are you sure you want to force Booking #${bookingId} to Completed? This will release the payout and notify both parties.`)) {
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Processing...';
                try {
                    const res = await fetch('ajax_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            user_id: user.id,
                            action: 'force_complete_booking',
                            booking_id: bookingId
                        })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        loadBookingsCrud();
                    } else {
                        showToast(data.message || 'Action failed', 'error');
                        btn.disabled = false;
                        btn.textContent = 'Complete';
                    }
                } catch (err) {
                    showToast('Failed to complete booking', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Complete';
                }
            });
        });

        body.querySelectorAll('.btn-cancel-refund').forEach(btn => {
            btn.addEventListener('click', async () => {
                const bookingId = Number(btn.dataset.id);
                if (!confirm(`Are you absolutely sure you want to cancel and refund Booking #${bookingId}? This action cannot be undone.`)) {
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Processing...';
                try {
                    const res = await fetch('ajax_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            user_id: user.id,
                            action: 'cancel_refund_booking',
                            booking_id: bookingId
                        })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        loadBookingsCrud();
                    } else {
                        showToast(data.message || 'Action failed', 'error');
                        btn.disabled = false;
                        btn.textContent = 'Cancel & Refund';
                    }
                } catch (err) {
                    showToast('Failed to cancel booking', 'error');
                    btn.disabled = false;
                    btn.textContent = 'Cancel & Refund';
                }
            });
        });
    }

    // Bookings Search Filter
    const searchBookingsInput = document.getElementById('searchBookings');
    if (searchBookingsInput) {
        searchBookingsInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawBookingsList.filter(b => 
                b.client_first_name.toLowerCase().includes(query) || 
                b.client_last_name.toLowerCase().includes(query) || 
                b.buddy_name.toLowerCase().includes(query) || 
                b.status.toLowerCase().includes(query)
            );
            renderBookingsTable(filtered);
        });
    }

    // --- 5. VOUCHERS CRUD CONTROLLER ---
    async function loadVouchersCrud() {
        const body = document.getElementById('crudVouchersTableBody');
        body.innerHTML = '<tr><td colspan="8" class="loading-state">Loading vouchers...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_vouchers&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawVouchersList = data.vouchers || [];
                renderVouchersTable(rawVouchersList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="8" class="loading-state" style="color: red;">Error loading vouchers.</td></tr>';
        }
    }

    function renderVouchersTable(list) {
        const body = document.getElementById('crudVouchersTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="8" class="loading-state">No vouchers found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(v => {
            const isPercent = v.discount_type === 'percentage';
            const valueDisp = isPercent ? `${v.discount_value}%` : `₱${v.discount_value}`;
            return `
                <tr>
                    <td><strong>${v.voucher_id}</strong></td>
                    <td><code>${escapeHtml(v.code)}</code></td>
                    <td>${escapeHtml(v.discount_type.toUpperCase())}</td>
                    <td>${valueDisp}</td>
                    <td>₱${v.min_spend}</td>
                    <td><span class="badge ${v.is_active == 1 ? 'badge-success' : 'badge-warning'}">${v.is_active == 1 ? 'ACTIVE' : 'DISABLED'}</span></td>
                    <td>${v.expiration_date ? escapeHtml(v.expiration_date) : 'Never'}</td>
                    <td>
                        <div class="action-btn-group">
                            <button type="button" class="action-icon-btn btn-edit" data-id="${v.voucher_id}" title="Edit Voucher">✏️</button>
                            <button type="button" class="action-icon-btn btn-delete" data-id="${v.voucher_id}" title="Delete Voucher">🗑️</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        // Bind Actions
        body.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetVoucher = rawVouchersList.find(v => v.voucher_id === Number(btn.dataset.id));
                openCrudModal('vouchers', 'edit', targetVoucher);
            });
        });
        body.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', () => {
                deleteRecord('vouchers', Number(btn.dataset.id));
            });
        });
    }

    // Vouchers Search Filter
    const searchVouchersInput = document.getElementById('searchVouchers');
    if (searchVouchersInput) {
        searchVouchersInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawVouchersList.filter(v => 
                v.code.toLowerCase().includes(query)
            );
            renderVouchersTable(filtered);
        });
    }

    // --- 5B. AUDIT LOGS CONTROLLER ---
    async function loadAuditLogsCrud() {
        const body = document.getElementById('crudLogsTableBody');
        body.innerHTML = '<tr><td colspan="7" class="loading-state">Loading audit logs...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_audit_logs&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawAuditLogsList = data.logs || [];
                renderAuditLogsTable(rawAuditLogsList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="7" class="loading-state" style="color: red;">Error loading logs.</td></tr>';
        }
    }

    function renderAuditLogsTable(list) {
        const body = document.getElementById('crudLogsTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="loading-state">No audit logs found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(log => {
            const perfDisp = log.first_name ? `${escapeHtml(log.first_name)} ${escapeHtml(log.last_name)} (<small>${escapeHtml(log.email)}</small>)` : '<em>System/Deleted</em>';
            
            // Map action classes for color-coded badges
            let actClass = 'other';
            const action = log.action.toLowerCase();
            if (action.includes('create') || action.includes('add') || action.includes('register') || action.includes('approve')) {
                actClass = 'add';
            } else if (action.includes('update') || action.includes('verify') || action.includes('accept') || action.includes('resolve')) {
                actClass = 'update';
            } else if (action.includes('delete') || action.includes('cancel') || action.includes('decline') || action.includes('reject')) {
                actClass = 'delete';
            }

            return `
                <tr>
                    <td><strong>${log.log_id}</strong></td>
                    <td><small>${escapeHtml(log.created_at)}</small></td>
                    <td>${perfDisp}</td>
                    <td><span class="action-badge ${actClass}">${escapeHtml(log.action.toUpperCase())}</span></td>
                    <td><code>${escapeHtml(log.entity_type.toUpperCase())}</code></td>
                    <td>${log.entity_id}</td>
                    <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(log.details)}">${escapeHtml(log.details)}</td>
                </tr>
            `;
        }).join('');
    }

    const searchLogsInput = document.getElementById('searchLogs');
    if (searchLogsInput) {
        searchLogsInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawAuditLogsList.filter(log => {
                const name = (log.first_name || '') + ' ' + (log.last_name || '');
                const email = log.email || '';
                return log.action.toLowerCase().includes(query) ||
                       log.entity_type.toLowerCase().includes(query) ||
                       log.details.toLowerCase().includes(query) ||
                       name.toLowerCase().includes(query) ||
                       email.toLowerCase().includes(query);
            });
            renderAuditLogsTable(filtered);
        });
    }

    // --- 5C. CHAT HISTORY CONTROLLER ---
    async function loadChatThreadsCrud() {
        const body = document.getElementById('crudChatsTableBody');
        body.innerHTML = '<tr><td colspan="6" class="loading-state">Loading chat threads...</td></tr>';
        try {
            const res = await fetch(`ajax_admin.php?action=list_chats&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                rawChatThreadsList = data.chats || [];
                renderChatThreadsTable(rawChatThreadsList);
            }
        } catch (err) {
            body.innerHTML = '<tr><td colspan="6" class="loading-state" style="color: red;">Error loading chats.</td></tr>';
        }
    }

    function renderChatThreadsTable(list) {
        const body = document.getElementById('crudChatsTableBody');
        if (list.length === 0) {
            body.innerHTML = '<tr><td colspan="6" class="loading-state">No chat threads found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(c => {
            return `
                <tr>
                    <td><strong>#${c.booking_id}</strong></td>
                    <td><small>${escapeHtml(c.booking_date)} ${escapeHtml(c.start_time)}</small></td>
                    <td>${escapeHtml(c.client_first_name)} ${escapeHtml(c.client_last_name)} (<small>${escapeHtml(c.client_email)}</small>)</td>
                    <td><strong>${escapeHtml(c.buddy_name)}</strong></td>
                    <td><span class="badge badge-info">${c.message_count} messages</span></td>
                    <td>
                        <button type="button" class="admin-btn admin-btn-primary admin-btn-sm btn-view-chat" data-id="${c.booking_id}">👁️ View Chat</button>
                    </td>
                </tr>
            `;
        }).join('');

        // Bind chat view buttons
        body.querySelectorAll('.btn-view-chat').forEach(btn => {
            btn.addEventListener('click', () => {
                openChatPreviewModal(Number(btn.dataset.id));
            });
        });
    }

    const searchChatsInput = document.getElementById('searchChats');
    if (searchChatsInput) {
        searchChatsInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const filtered = rawChatThreadsList.filter(c => {
                const client = (c.client_first_name || '') + ' ' + (c.client_last_name || '');
                const email = c.client_email || '';
                const buddy = c.buddy_name || '';
                return client.toLowerCase().includes(query) ||
                       email.toLowerCase().includes(query) ||
                       buddy.toLowerCase().includes(query);
            });
            renderChatThreadsTable(filtered);
        });
    }

    // Modal dialogue stream handler
    const chatHistoryPreviewModal = document.getElementById('chatHistoryPreviewModal');
    const chatPreviewStream = document.getElementById('chatPreviewStream');
    const closeChatPreviewModalBtn = document.getElementById('closeChatPreviewModalBtn');
    const btnExitChatPreview = document.getElementById('btnExitChatPreview');

    async function openChatPreviewModal(bookingId) {
        chatPreviewStream.innerHTML = '<div class="loading-state">Loading messages...</div>';
        chatHistoryPreviewModal.style.display = 'flex';
        try {
            const res = await fetch(`ajax_admin.php?action=get_chat_messages&booking_id=${bookingId}&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                const messages = data.messages || [];
                if (messages.length === 0) {
                    chatPreviewStream.innerHTML = '<div class="loading-state">No messages in this thread.</div>';
                    return;
                }
                chatPreviewStream.innerHTML = messages.map(m => {
                    const isBuddy = m.role === 'buddy';
                    const bubbleTypeClass = isBuddy ? 'chat-bubble-buddy' : 'chat-bubble-client';
                    return `
                        <div class="chat-bubble ${bubbleTypeClass}">
                            <span class="chat-bubble-sender">${escapeHtml(m.first_name)} ${escapeHtml(m.last_name)} (${escapeHtml(m.role.toUpperCase())})</span>
                            <div class="chat-bubble-text">${escapeHtml(m.message_text)}</div>
                            <span class="chat-bubble-meta">${escapeHtml(m.created_at)}</span>
                        </div>
                    `;
                }).join('');
                
                // Auto-scroll to bottom of preview stream
                chatPreviewStream.scrollTop = chatPreviewStream.scrollHeight;
            } else {
                chatPreviewStream.innerHTML = `<div class="loading-state" style="color: red;">${data.message || 'Error loading.'}</div>`;
            }
        } catch (err) {
            chatPreviewStream.innerHTML = '<div class="loading-state" style="color: red;">Network error loading messages.</div>';
        }
    }

    function closeChatPreviewModal() {
        chatHistoryPreviewModal.style.display = 'none';
        chatPreviewStream.innerHTML = '';
    }

    if (closeChatPreviewModalBtn) closeChatPreviewModalBtn.addEventListener('click', closeChatPreviewModal);
    if (btnExitChatPreview) btnExitChatPreview.addEventListener('click', closeChatPreviewModal);
    window.addEventListener('click', (e) => {
        if (e.target === chatHistoryPreviewModal) closeChatPreviewModal();
    });

    // --- 6. MODAL CRUD OPEN/CLOSE & SUBMIT CONTROLLERS ---
    async function openCrudModal(entity, mode, data = null) {
        await fetchFormLookups();
        crudForm.dataset.entity = entity;
        crudForm.dataset.mode = mode;
        const singularEntity = entity === 'buddies' ? 'buddy' : entity.substring(0, entity.length - 1);
        if (mode === 'add') {
            crudModalTitle.textContent = `Add New ${singularEntity.toUpperCase()}`;
        } else {
            crudModalTitle.textContent = `Edit ${singularEntity.toUpperCase()} (ID: ${data.user_id || data.profile_id || data.booking_id || data.id})`;
        }

        // Dynamically build fields
        let fieldsHtml = '';

        if (entity === 'users') {
            fieldsHtml = `
                ${mode === 'edit' ? `<input type="hidden" name="target_user_id" value="${data.user_id}">` : ''}
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="${data ? escapeHtml(data.first_name) : ''}" required>
                    </div>
                    <div class="crud-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="${data ? escapeHtml(data.last_name) : ''}" required>
                    </div>
                </div>
                <div class="crud-field">
                    <label>Email Address</label>
                    <input type="email" name="email" value="${data ? escapeHtml(data.email) : ''}" required>
                </div>
                <div class="crud-field">
                    <label>Password ${mode === 'edit' ? '<small style="color: var(--text-muted); font-weight: normal;">(Leave blank to keep current)</small>' : ''}</label>
                    <input type="password" name="password" ${mode === 'add' ? 'required' : ''}>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Role</label>
                        <select name="role">
                            <option value="client" ${data && data.role === 'client' ? 'selected' : ''}>Client</option>
                            <option value="buddy" ${data && data.role === 'buddy' ? 'selected' : ''}>Buddy</option>
                            <option value="admin" ${data && data.role === 'admin' ? 'selected' : ''}>Admin</option>
                        </select>
                    </div>
                    <div class="crud-field">
                        <label>Pronouns</label>
                        <input type="text" name="pronouns" value="${data && data.pronouns ? escapeHtml(data.pronouns) : ''}">
                    </div>
                </div>
                <div class="crud-field">
                    <label>Profile Photo URL</label>
                    <input type="text" name="profile_photo" value="${data && data.profile_photo ? escapeHtml(data.profile_photo) : ''}">
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="active" ${data && data.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="suspended" ${data && data.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                            <option value="banned" ${data && data.status === 'banned' ? 'selected' : ''}>Banned</option>
                        </select>
                    </div>
                    <div class="crud-field">
                        <label>Activation Status</label>
                        <select name="is_active">
                            <option value="1" ${data && data.is_active == 1 ? 'selected' : ''}>Active / Enabled</option>
                            <option value="0" ${data && data.is_active == 0 ? 'selected' : ''}>Disabled / Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="crud-field">
                    <label>Status Reason</label>
                    <input type="text" name="status_reason" value="${data && data.status_reason ? escapeHtml(data.status_reason) : ''}">
                </div>
            `;
        } else if (entity === 'buddies') {
            const userOptions = (lookupsData.all_users || []).map(u => 
                `<option value="${u.user_id}" ${data && data.user_id === u.user_id ? 'selected' : ''}>${escapeHtml(u.first_name)} ${escapeHtml(u.last_name)} (${escapeHtml(u.email)})</option>`
            ).join('');

            fieldsHtml = `
                ${mode === 'edit' ? `<input type="hidden" name="profile_id" value="${data.profile_id}">` : ''}
                <div class="crud-field">
                    <label>Linked User</label>
                    <select name="target_user_id" ${mode === 'edit' ? 'disabled' : ''}>
                        ${userOptions}
                    </select>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Display Name</label>
                        <input type="text" name="display_name" value="${data ? escapeHtml(data.display_name) : ''}" required>
                    </div>
                    <div class="crud-field">
                        <label>Specialty Category</label>
                        <select name="category" required>
                            <option value="casual" ${data && data.category === 'casual' ? 'selected' : ''}>Casual Hangout ☕</option>
                            <option value="event" ${data && data.category === 'event' ? 'selected' : ''}>Event Plus-One 🎉</option>
                            <option value="security" ${data && data.category === 'security' ? 'selected' : ''}>Bodyguard & Security 🛡️</option>
                            <option value="arts" ${data && data.category === 'arts' ? 'selected' : ''}>Visual Arts 🎨</option>
                            <option value="listener" ${data && data.category === 'listener' ? 'selected' : ''}>Active Listener 👂</option>
                            <option value="ally" ${data && data.category === 'ally' ? 'selected' : ''}>LGBTQ+ Ally 🏳️‍🌈</option>
                        </select>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Professional Specialty Title</label>
                        <input type="text" name="professional_title" value="${data ? escapeHtml(data.professional_title) : ''}" required>
                    </div>
                    <div class="crud-field">
                        <label>Hourly Rate (₱/hr)</label>
                        <input type="number" name="hourly_rate" value="${data ? data.hourly_rate : '150'}" min="0" required>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Cavite Municipality</label>
                        <select name="location" required>
                            <option value="Indang" ${data && data.location === 'Indang' ? 'selected' : ''}>Indang (CvSU Main)</option>
                            <option value="Tanza" ${data && data.location === 'Tanza' ? 'selected' : ''}>Tanza</option>
                            <option value="Trece Martires" ${data && data.location === 'Trece Martires' ? 'selected' : ''}>Trece Martires</option>
                            <option value="General Trias" ${data && data.location === 'General Trias' ? 'selected' : ''}>General Trias</option>
                            <option value="Naic" ${data && data.location === 'Naic' ? 'selected' : ''}>Naic</option>
                            <option value="Silang" ${data && data.location === 'Silang' ? 'selected' : ''}>Silang</option>
                            <option value="Tagaytay" ${data && data.location === 'Tagaytay' ? 'selected' : ''}>Tagaytay</option>
                            <option value="Imus" ${data && data.location === 'Imus' ? 'selected' : ''}>Imus</option>
                            <option value="Dasmariñas" ${data && data.location === 'Dasmariñas' ? 'selected' : ''}>Dasmariñas</option>
                            <option value="Bacoor" ${data && data.location === 'Bacoor' ? 'selected' : ''}>Bacoor</option>
                            <option value="Rosario" ${data && data.location === 'Rosario' ? 'selected' : ''}>Rosario</option>
                        </select>
                    </div>
                    <div class="crud-field">
                        <label>Availability Description</label>
                        <input type="text" name="availability" value="${data ? escapeHtml(data.availability) : ''}" placeholder="e.g. Weekends only" required>
                    </div>
                </div>
                <div class="crud-field">
                    <label>Public Bio / Services Description</label>
                    <textarea name="bio" rows="3" required>${data ? escapeHtml(data.bio) : ''}</textarea>
                </div>
                <div class="crud-field">
                    <label>Status</label>
                    <select name="is_available">
                        <option value="1" ${data && data.is_available == 1 ? 'selected' : ''}>Active / Available</option>
                        <option value="0" ${data && data.is_available == 0 ? 'selected' : ''}>Away / Unavailable</option>
                    </select>
                </div>
            `;
        } else if (entity === 'bookings') {
            const clientOptions = (lookupsData.clients || []).map(c => 
                `<option value="${c.user_id}" ${data && data.client_id === c.user_id ? 'selected' : ''}>${escapeHtml(c.first_name)} ${escapeHtml(c.last_name)} (${escapeHtml(c.email)})</option>`
            ).join('');
            
            const buddyOptions = (lookupsData.buddies || []).map(b => 
                `<option value="${b.profile_id}" ${data && data.buddy_profile_id === b.profile_id ? 'selected' : ''}>${escapeHtml(b.display_name)}</option>`
            ).join('');

            const statusOptions = (lookupsData.statuses || []).map(s => 
                `<option value="${s.status_id}" ${data && data.status_id === s.status_id ? 'selected' : ''}>${escapeHtml(s.status_name)}</option>`
            ).join('');

            fieldsHtml = `
                ${mode === 'edit' ? `<input type="hidden" name="booking_id" value="${data.booking_id}">` : ''}
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Client</label>
                        <select name="client_id" required>
                            ${clientOptions}
                        </select>
                    </div>
                    <div class="crud-field">
                        <label>Buddy Profile</label>
                        <select name="buddy_profile_id" required>
                            ${buddyOptions}
                        </select>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Booking Date</label>
                        <input type="date" name="booking_date" value="${data ? data.booking_date : ''}" required>
                    </div>
                    <div class="crud-field">
                        <label>Start Time</label>
                        <input type="time" name="start_time" value="${data ? data.start_time.substring(0, 5) : ''}" required>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Duration (Hours)</label>
                        <input type="number" name="hours_duration" value="${data ? data.hours_duration : '1'}" step="0.5" min="0.5" required>
                    </div>
                    <div class="crud-field">
                        <label>Status</label>
                        <select name="status_id" required>
                            ${statusOptions}
                        </select>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Base Price (₱)</label>
                        <input type="number" name="base_price" value="${data ? data.base_price : '0'}" min="0" required>
                    </div>
                    <div class="crud-field">
                        <label>Discount Amount (₱)</label>
                        <input type="number" name="discount_amount" value="${data ? data.discount_amount : '0'}" min="0" required>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Platform Fee (₱)</label>
                        <input type="number" name="platform_fee" value="${data ? data.platform_fee : '0'}" min="0" required>
                    </div>
                    <div class="crud-field">
                        <label>Total Price (₱)</label>
                        <input type="number" name="total_price" value="${data ? data.total_price : '0'}" min="0" required>
                    </div>
                </div>
                <div class="crud-field">
                    <label>Payment Method</label>
                    <select name="payment_method" required>
                        <option value="Card" ${data && data.payment_method === 'Card' ? 'selected' : ''}>Card</option>
                        <option value="Cash On Hand" ${data && data.payment_method === 'Cash On Hand' ? 'selected' : ''}>Cash On Hand</option>
                    </select>
                </div>
                <div class="crud-field">
                    <label>Customer Message</label>
                    <textarea name="message" rows="2">${data && data.message ? escapeHtml(data.message) : ''}</textarea>
                </div>
            `;
        } else if (entity === 'vouchers') {
            fieldsHtml = `
                ${mode === 'edit' ? `<input type="hidden" name="voucher_id" value="${data.voucher_id}">` : ''}
                <div class="crud-field">
                    <label>Promo Code</label>
                    <input type="text" name="code" value="${data ? escapeHtml(data.code) : ''}" required>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Discount Type</label>
                        <select name="discount_type" required>
                            <option value="fixed" ${data && data.discount_type === 'fixed' ? 'selected' : ''}>Fixed Amount (₱)</option>
                            <option value="percentage" ${data && data.discount_type === 'percentage' ? 'selected' : ''}>Percentage (%)</option>
                        </select>
                    </div>
                    <div class="crud-field">
                        <label>Discount Value</label>
                        <input type="number" name="discount_value" value="${data ? data.discount_value : '0'}" min="0" required>
                    </div>
                </div>
                <div class="crud-field-row">
                    <div class="crud-field">
                        <label>Min Spend Threshold (₱)</label>
                        <input type="number" name="min_spend" value="${data ? data.min_spend : '0'}" min="0" required>
                    </div>
                    <div class="crud-field">
                        <label>Status</label>
                        <select name="is_active">
                            <option value="1" ${data && data.is_active == 1 ? 'selected' : ''}>Active</option>
                            <option value="0" ${data && data.is_active == 0 ? 'selected' : ''}>Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="crud-field">
                    <label>Expiration Date</label>
                    <input type="date" name="expiration_date" value="${data && data.expiration_date ? data.expiration_date : ''}">
                </div>
            `;
        }

        crudModalFields.innerHTML = fieldsHtml;
        crudFormModal.style.display = 'flex';
    }

    function closeCrudModal() {
        crudFormModal.style.display = 'none';
        crudForm.reset();
    }

    if (closeCrudModalBtn) closeCrudModalBtn.addEventListener('click', closeCrudModal);
    if (btnCancelCrud) btnCancelCrud.addEventListener('click', closeCrudModal);
    window.addEventListener('click', (e) => {
        if (e.target === crudFormModal) closeCrudModal();
    });

    // Add Buttons Listeners
    const btnAddUser = document.getElementById('btnAddUser');
    if (btnAddUser) btnAddUser.addEventListener('click', () => openCrudModal('users', 'add'));

    const btnAddBuddy = document.getElementById('btnAddBuddy');
    if (btnAddBuddy) btnAddBuddy.addEventListener('click', () => openCrudModal('buddies', 'add'));

    const btnAddBooking = document.getElementById('btnAddBooking');
    if (btnAddBooking) btnAddBooking.addEventListener('click', () => openCrudModal('bookings', 'add'));

    const btnAddVoucher = document.getElementById('btnAddVoucher');
    if (btnAddVoucher) btnAddVoucher.addEventListener('click', () => openCrudModal('vouchers', 'add'));

    // --- FORM SAVE SUBMISSION CONTROLLER ---
    if (crudForm) {
        crudForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const entity = crudForm.dataset.entity;
            const mode = crudForm.dataset.mode;
            
            const formData = new FormData(crudForm);
            const payload = {};
            formData.forEach((val, key) => {
                payload[key] = val;
            });

            // Map entity + mode to the exact action name expected by the backend
            const singularEntity = entity === 'buddies' ? 'buddy' : entity.substring(0, entity.length - 1);
            const backendAction = mode === 'add' ? `create_${singularEntity}` : `update_${singularEntity}`;

            // Add standard actions and credentials details
            payload.user_id = user.id;
            payload.action = backendAction;

            // Typecasting helpers
            if (payload.target_user_id) payload.target_user_id = Number(payload.target_user_id);
            if (payload.profile_id) payload.profile_id = Number(payload.profile_id);
            if (payload.booking_id) payload.booking_id = Number(payload.booking_id);
            if (payload.voucher_id) payload.voucher_id = Number(payload.voucher_id);
            if (payload.client_id) payload.client_id = Number(payload.client_id);
            if (payload.buddy_profile_id) payload.buddy_profile_id = Number(payload.buddy_profile_id);
            if (payload.status_id) payload.status_id = Number(payload.status_id);
            if (payload.hours_duration) payload.hours_duration = Number(payload.hours_duration);
            if (payload.base_price) payload.base_price = Number(payload.base_price);
            if (payload.discount_amount) payload.discount_amount = Number(payload.discount_amount);
            if (payload.platform_fee) payload.platform_fee = Number(payload.platform_fee);
            if (payload.total_price) payload.total_price = Number(payload.total_price);
            if (payload.hourly_rate) payload.hourly_rate = Number(payload.hourly_rate);
            if (payload.discount_value) payload.discount_value = Number(payload.discount_value);
            if (payload.min_spend) payload.min_spend = Number(payload.min_spend);
            if (payload.is_active) payload.is_active = Number(payload.is_active);
            if (payload.is_available) payload.is_available = Number(payload.is_available);

            const saveBtn = document.getElementById('btnSaveCrud');
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const res = await fetch('ajax_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message || 'Record saved successfully!', 'success');
                    closeCrudModal();
                    
                    // Reload active table view
                    if (entity === 'users') loadUsersCrud();
                    else if (entity === 'buddies') loadBuddiesCrud();
                    else if (entity === 'bookings') loadBookingsCrud();
                    else if (entity === 'vouchers') loadVouchersCrud();
                } else {
                    showToast(data.message || 'Failed to save record.', 'error');
                }
            } catch (err) {
                showToast('Network error saving record.', 'error');
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Record';
            }
        });
    }

    // --- DELETE RECORD TRIGGER ---
    async function deleteRecord(entity, id) {
        const singularEntity = entity === 'buddies' ? 'buddy' : entity.substring(0, entity.length - 1);
        if (!confirm(`Are you absolutely sure you want to delete this ${singularEntity}? This action cannot be undone.`)) {
            return;
        }

        const payload = {
            user_id: user.id,
            action: `delete_${singularEntity}`
        };

        if (entity === 'users') payload.target_user_id = id;
        else if (entity === 'buddies') payload.profile_id = id;
        else if (entity === 'bookings') payload.booking_id = id;
        else if (entity === 'vouchers') payload.voucher_id = id;

        try {
            const res = await fetch('ajax_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.status === 'success') {
                showToast(data.message || 'Record deleted successfully.', 'success');
                
                // Reload active table view
                if (entity === 'users') loadUsersCrud();
                else if (entity === 'buddies') loadBuddiesCrud();
                else if (entity === 'bookings') loadBookingsCrud();
                else if (entity === 'vouchers') loadVouchersCrud();
            } else {
                showToast(data.message || 'Failed to delete record.', 'error');
            }
        } catch (err) {
            showToast('Network error deleting record.', 'error');
        }
    }

    // --- 7. SYSTEM SETTINGS CONTROLLER ---
    async function loadSystemSettings() {
        try {
            const res = await fetch(`ajax_admin.php?action=list_system_settings&user_id=${user.id}`);
            const data = await res.json();
            if (data.status === 'success') {
                const settings = data.settings || {};
                const inputCommissionRate = document.getElementById('inputCommissionRate');
                const inputServiceFee = document.getElementById('inputServiceFee');
                const inputMaintenanceMode = document.getElementById('inputMaintenanceMode');
                
                if (inputCommissionRate) inputCommissionRate.value = settings.commission_rate ?? '10';
                if (inputServiceFee) inputServiceFee.value = settings.service_fee ?? '50';
                if (inputMaintenanceMode) inputMaintenanceMode.value = settings.maintenance_mode ?? '0';
            } else {
                showToast(data.message || 'Failed to load system settings.', 'error');
            }
        } catch (err) {
            showToast('Error loading system settings.', 'error');
        }
    }

    const systemSettingsForm = document.getElementById('systemSettingsForm');
    if (systemSettingsForm) {
        systemSettingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btnSaveSettings = document.getElementById('btnSaveSettings');
            if (btnSaveSettings) {
                btnSaveSettings.disabled = true;
                btnSaveSettings.textContent = 'Saving...';
            }
            
            const commission_rate = document.getElementById('inputCommissionRate').value;
            const service_fee = document.getElementById('inputServiceFee').value;
            const maintenance_mode = document.getElementById('inputMaintenanceMode').value;
            
            try {
                const res = await fetch('ajax_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: user.id,
                        action: 'update_system_settings',
                        commission_rate: Number(commission_rate),
                        service_fee: Number(service_fee),
                        maintenance_mode: Number(maintenance_mode)
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message || 'System settings updated successfully!', 'success');
                } else {
                    showToast(data.message || 'Failed to update system settings.', 'error');
                }
            } catch (err) {
                showToast('Error updating system settings.', 'error');
            } finally {
                if (btnSaveSettings) {
                    btnSaveSettings.disabled = false;
                    btnSaveSettings.textContent = 'Save Platform Settings';
                }
            }
        });
    }

    // --- INITIAL PANEL LOADS ---
    loadDashboardData();
}


function initHomepage() {
    const searchForm = document.getElementById('homepageSearchForm');
    if (searchForm) {
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const query = document.getElementById('heroSearchInput')?.value.trim() || '';
            const categorySelect = document.getElementById('heroCategorySelect')?.value || '';
            
            let categoryParam = '';
            if (categorySelect) {
                if (categorySelect === 'Academic Tutor') {
                    categoryParam = 'arts';
                } else if (categorySelect === 'Gaming Companion') {
                    categoryParam = 'casual';
                } else if (categorySelect === 'Tour Guide') {
                    categoryParam = 'casual';
                } else if (categorySelect === 'Event Companion') {
                    categoryParam = 'event';
                } else if (categorySelect === 'Personal Helper') {
                    categoryParam = 'casual';
                }
            }

            const searchParams = new URLSearchParams();
            if (query) {
                searchParams.set('query', query);
            }
            if (categoryParam) {
                searchParams.set('category', categoryParam);
            }

            window.location.href = `marketplace.html?${searchParams.toString()}`;
        });
    }

    const popularGrid = document.getElementById('popularBuddiesGrid');
    if (popularGrid) {
        (async function fetchPopularBuddies() {
            try {
                const response = await fetch('ajax_marketplace.php?per_page=4&sort=rating', {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('Failed to load buddies.');
                const data = await response.json();

                if (data.status === 'success' && data.buddies && data.buddies.length > 0) {
                    popularGrid.innerHTML = '';
                    data.buddies.forEach((buddy, idx) => {
                        const isOnline = (buddy.id % 3 === 0);
                        const statusClass = isOnline ? 'online' : 'offline';
                        const statusText = isOnline ? 'Online' : 'Offline';
                        const isVerified = buddy.is_verified;
                        const verifiedCheck = isVerified ? '<span class="card-verified-check">✓</span>' : '';
                        
                        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                        const fallbackAvatar = isDark ? 'images/user-dark.png' : 'images/user-light.png';
                        const avatarSrc = (buddy.avatar_url && buddy.avatar_url.trim() !== '' && buddy.avatar_url !== 'images/user-light.png')
                            ? buddy.avatar_url
                            : fallbackAvatar;

                        const ratingVal = buddy.rating > 0 ? buddy.rating.toFixed(1) : '5.0';
                        const reviewCount = buddy.review_count > 0 ? buddy.review_count : Math.floor(Math.random() * 15) + 5;

                        const cardHtml = `
                        <article class="homepage-buddy-card" onclick="window.location.href='profile.html?id=${buddy.id}'">
                            <div class="card-img-container">
                                <img src="${avatarSrc}" alt="${buddy.display_name}" onerror="this.src='${fallbackAvatar}'">
                                <div class="card-status-badge">
                                    <span class="status-dot ${statusClass}"></span>
                                    <span>${statusText}</span>
                                </div>
                                <span class="card-rate-pill">${buddy.hourly_rate_fmt}/hr</span>
                            </div>
                            <div class="card-details">
                                <h3>${buddy.display_name} ${verifiedCheck}</h3>
                                <p class="card-title-text">${buddy.professional_title}</p>
                                <div class="card-rating-row">
                                    <span class="card-rating-star">★</span>
                                    <span>${ratingVal}</span>
                                    <span class="card-review-count">(${reviewCount} review${reviewCount !== 1 ? 's' : ''})</span>
                                </div>
                            </div>
                        </article>
                        `;
                        popularGrid.insertAdjacentHTML('beforeend', cardHtml);
                    });
                } else {
                    renderPlaceholderPopularBuddies();
                }
            } catch (err) {
                renderPlaceholderPopularBuddies();
            }
        })();
    }

    function renderPlaceholderPopularBuddies() {
        if (!popularGrid) return;
        popularGrid.innerHTML = '';
        
        const fallbacks = [
            { id: 3, name: 'Angelo Maduro', title: 'Personal Bodyguard & Intimidation', rate: '₱400', rating: '4.9', reviews: 25, avatar: 'images/Angelo_Maduro.jpg', verified: true, online: true },
            { id: 5, name: 'Liah Faith Espineli', title: 'Professional Pianist & Music Teacher', rate: '₱500', rating: '5.0', reviews: 18, avatar: 'images/Liah_Faith.jpg', verified: true, online: false },
            { id: 8, name: 'Toper Claveria', title: 'Actor & Social Roleplay Specialist', rate: '₱600', rating: '4.8', reviews: 12, avatar: 'images/toper1.png', verified: true, online: true },
            { id: 10, name: 'Dominic Berdonar', title: 'Queue & Errand Proxy Specialist', rate: '₱150', rating: '4.7', reviews: 120, avatar: 'images/buddies/dominic_berdonar_1.jpg', verified: true, online: false }
        ];

        fallbacks.forEach(buddy => {
            const statusClass = buddy.online ? 'online' : 'offline';
            const statusText = buddy.online ? 'Online' : 'Offline';
            const verifiedCheck = buddy.verified ? '<span class="card-verified-check">✓</span>' : '';
            
            const cardHtml = `
            <article class="homepage-buddy-card" onclick="window.location.href='profile.html?id=${buddy.id}'">
                <div class="card-img-container">
                    <img src="${buddy.avatar}" alt="${buddy.name}" onerror="this.src='images/user-light.png'">
                    <div class="card-status-badge">
                        <span class="status-dot ${statusClass}"></span>
                        <span>${statusText}</span>
                    </div>
                    <span class="card-rate-pill">${buddy.rate}/hr</span>
                </div>
                <div class="card-details">
                    <h3>${buddy.name} ${verifiedCheck}</h3>
                    <p class="card-title-text">${buddy.title}</p>
                    <div class="card-rating-row">
                        <span class="card-rating-star">★</span>
                        <span>${buddy.rating}</span>
                        <span class="card-review-count">(${buddy.reviews} reviews)</span>
                    </div>
                </div>
            </article>
            `;
            popularGrid.insertAdjacentHTML('beforeend', cardHtml);
        });
    }
}

function initCommunityHub() {
    const postsFeed = document.getElementById('postsFeed');
    if (!postsFeed) return;

    const createPostCard = document.getElementById('createPostCard');
    const guestCtaCard = document.getElementById('guestCtaCard');
    const currentUserAvatar = document.getElementById('currentUserAvatar');
    const postContent = document.getElementById('postContent');
    const charCount = document.getElementById('charCount');
    const submitPostBtn = document.getElementById('submitPostBtn');
    const postCategory = document.getElementById('postCategory');

    // Report modal elements
    const reportModal = document.getElementById('reportModal');
    const closeReportModalBtn = document.getElementById('closeReportModalBtn');
    const cancelReportBtn = document.getElementById('cancelReportBtn');
    const submitReportBtn = document.getElementById('submitReportBtn');
    const reportPostId = document.getElementById('reportPostId');
    const reportCommentId = document.getElementById('reportCommentId');
    const reportReason = document.getElementById('reportReason');
    const reportDetails = document.getElementById('reportDetails');

    const currentUser = getCurrentUser();

    // ── Update Auth UI ──────────────────────────────────────────
    if (currentUser) {
        if (createPostCard) createPostCard.style.display = 'block';
        if (guestCtaCard) guestCtaCard.style.display = 'none';
        if (currentUserAvatar) {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const fallbackAvatar = isDark ? 'images/user-dark.png' : 'images/user-light.png';
            currentUserAvatar.src = (currentUser.avatar_url && currentUser.avatar_url.trim() !== '') ? currentUser.avatar_url : fallbackAvatar;
        }
    } else {
        if (createPostCard) createPostCard.style.display = 'none';
        if (guestCtaCard) guestCtaCard.style.display = 'block';
    }

    // ── Textarea character counter ──────────────────────────────
    if (postContent) {
        postContent.addEventListener('input', () => {
            const len = postContent.value.length;
            if (charCount) charCount.textContent = String(len);
            if (submitPostBtn) {
                submitPostBtn.disabled = (len === 0);
            }
        });
    }

    // ── Helper to format time ago ───────────────────────────────
    function timeAgo(dateString) {
        try {
            const date = new Date(dateString.replace(/-/g, "/"));
            const now = new Date();
            const diffSeconds = Math.abs(Math.floor((now.getTime() - date.getTime()) / 1000));
            
            if (diffSeconds < 60) return 'Just now';
            const diffMinutes = Math.floor(diffSeconds / 60);
            if (diffMinutes < 60) return `${diffMinutes}m ago`;
            const diffHours = Math.floor(diffMinutes / 60);
            if (diffHours < 24) return `${diffHours}h ago`;
            const diffDays = Math.floor(diffHours / 24);
            if (diffDays < 30) return `${diffDays}d ago`;
            
            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) {
            return dateString;
        }
    }

    // ── Fetch & Render Community Feed ───────────────────────────
    async function loadFeed() {
        postsFeed.innerHTML = `
            <div class="feed-status-box">
                <div class="status-spinner"></div>
                <p>Loading community posts...</p>
            </div>
        `;

        try {
            const response = await fetch('ajax_community.php', {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });

            const data = await response.json();

            if (data.status === 'success') {
                renderFeed(data.feed, data.current_user_id, data.current_user_role);
            } else {
                postsFeed.innerHTML = `
                    <div class="feed-status-box">
                        <p style="color:#f44336">Failed to load feed: ${escapeHtml(data.message)}</p>
                    </div>
                `;
            }
        } catch (err) {
            postsFeed.innerHTML = `
                <div class="feed-status-box">
                    <p style="color:#f44336">Connection error — could not load community feed.</p>
                </div>
            `;
        }
    }

    function renderFeed(feed, currentUserId, currentUserRole) {
        if (!feed || feed.length === 0) {
            postsFeed.innerHTML = `
                <div class="feed-status-box">
                    <p style="opacity:0.6">No posts found in the hub. Be the first to share an update!</p>
                </div>
            `;
            return;
        }

        postsFeed.innerHTML = '';

        feed.forEach((post, idx) => {
            const isAuthor = (post.user_id === currentUserId);
            const isAdmin = (currentUserRole === 'admin');
            const hasLiked = post.user_has_liked;

            // Category tag config
            let catClass = 'cat-general';
            let catIcon = '☕';
            if (post.category === 'Booking Tips') { catClass = 'cat-tips'; catIcon = '💡'; }
            else if (post.category === 'Social Hangout') { catClass = 'cat-hangout'; catIcon = '🎉'; }
            else if (post.category === 'Safety Alert') { catClass = 'cat-safety'; catIcon = '🛡️'; }

            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const fallbackAvatar = isDark ? 'images/user-dark.png' : 'images/user-light.png';
            const avatarUrl = (post.avatar_url && post.avatar_url.trim() !== '') ? post.avatar_url : fallbackAvatar;

            const pinnedBadge = post.is_pinned ? `<span class="pinned-badge">📌 Pinned</span>` : '';
            const pinnedClass = post.is_pinned ? 'pinned-post' : '';

            // Comments lists
            let commentsHtml = '';
            post.comments.forEach(comment => {
                const commentAvatar = (comment.avatar_url && comment.avatar_url.trim() !== '') ? comment.avatar_url : fallbackAvatar;
                const isCommentAuthor = (comment.user_id === currentUserId);
                const deleteCommentBtn = (isCommentAuthor || isAdmin)
                    ? `<button type="button" class="comment-delete-trigger" data-comment-id="${comment.comment_id}">&times;</button>`
                    : '';

                const commentRoleBadge = comment.user_role !== 'client' 
                    ? `<span class="badge-role ${comment.user_role}-role">${comment.user_role}</span>` 
                    : '';

                commentsHtml += `
                    <div class="comment-item">
                        <img src="${commentAvatar}" alt="${escapeHtml(comment.author_name)}" class="comment-avatar" onerror="this.src='${fallbackAvatar}'">
                        <div class="comment-details">
                            <div class="comment-author-name">${escapeHtml(comment.author_name)} ${commentRoleBadge}</div>
                            <div class="comment-text">${escapeHtml(comment.content)}</div>
                            <div class="comment-time">${timeAgo(comment.created_at)}</div>
                        </div>
                        ${deleteCommentBtn}
                    </div>
                `;
            });

            // Author role badge
            const authorRoleBadge = post.user_role !== 'client'
                ? `<span class="badge-role ${post.user_role}-role">${post.user_role}</span>`
                : '';

            // Admin toolbar
            let adminToolbar = '';
            if (isAdmin) {
                const pinAction = post.is_pinned ? 'unpin_post' : 'pin_post';
                const pinLabel = post.is_pinned ? '📍 Unpin Post' : '📌 Pin Post';
                adminToolbar = `
                    <div class="admin-moderation-bar">
                        <span class="admin-mod-label">🛡️ Moderation Tools</span>
                        <div class="admin-mod-actions">
                            <button type="button" class="admin-mod-btn btn-pin" data-action="${pinAction}" data-post-id="${post.post_id}">${pinLabel}</button>
                            <button type="button" class="admin-mod-btn btn-delete" data-post-id="${post.post_id}">Delete Post</button>
                        </div>
                    </div>
                `;
            }

            // Post author delete action
            let authorDeleteBtn = '';
            if (isAuthor && !isAdmin) {
                authorDeleteBtn = `
                    <button type="button" class="feed-action-btn delete-post-btn" data-post-id="${post.post_id}" style="color:#f44336">
                        🗑️ Delete
                    </button>
                `;
            }

            const likeIconColor = hasLiked ? '#fe6fbe' : 'currentColor';
            const likeIconFill = hasLiked ? '#fe6fbe' : 'none';

            const postCard = `
                <div class="post-item-card ${pinnedClass}" data-post-id="${post.post_id}">
                    ${pinnedBadge}
                    <div class="post-item-header">
                        <img src="${avatarUrl}" alt="${escapeHtml(post.author_name)}" class="avatar-circle-sm" onerror="this.src='${fallbackAvatar}'">
                        <div class="post-meta-details">
                            <div class="post-author-name">${escapeHtml(post.author_name)} ${authorRoleBadge}</div>
                            <div class="post-timestamp">${timeAgo(post.created_at)}</div>
                        </div>
                        <span class="post-category-tag ${catClass}">${catIcon} ${post.category}</span>
                    </div>
                    <div class="post-item-content">${escapeHtml(post.content)}</div>
                    <div class="post-item-footer">
                        <button type="button" class="feed-action-btn like-action-btn ${hasLiked ? 'liked' : ''}" data-post-id="${post.post_id}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="${likeIconFill}" stroke="${likeIconColor}" stroke-width="2" style="display:inline-block; vertical-align:middle; margin-right:2px;">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                            </svg>
                            <span>${post.likes_count}</span>
                        </button>
                        <button type="button" class="feed-action-btn comment-toggle-btn" data-post-id="${post.post_id}">
                            💬 <span>Comments (${post.comments.length})</span>
                        </button>
                        <button type="button" class="feed-action-btn flag-action-btn" data-post-id="${post.post_id}">
                            🚩 Report
                        </button>
                        ${authorDeleteBtn}
                    </div>

                    ${adminToolbar}

                    <!-- Nested Comments Container -->
                    <div class="comments-section" id="comments-${post.post_id}" style="display:none;">
                        <div class="comments-list">${commentsHtml}</div>
                        ${currentUser ? `
                        <div class="add-comment-wrapper">
                            <input type="text" class="comment-input-box" placeholder="Write a comment..." data-post-id="${post.post_id}">
                            <button type="button" class="submit-comment-btn" data-post-id="${post.post_id}">
                                ➔
                            </button>
                        </div>` : ''}
                    </div>
                </div>
            `;
            postsFeed.insertAdjacentHTML('beforeend', postCard);
        });
    }

    // ── Submit Post ─────────────────────────────────────────────
    if (submitPostBtn) {
        submitPostBtn.addEventListener('click', async () => {
            const content = postContent.value.trim();
            const category = postCategory.value;

            if (content === '') return;

            setButtonLoading(submitPostBtn, true);

            try {
                const response = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_post',
                        content: content,
                        category: category
                    })
                });

                const data = await response.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    postContent.value = '';
                    if (charCount) charCount.textContent = '0';
                    submitPostBtn.disabled = true;
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to create post. Try again.', 'error');
            } finally {
                setButtonLoading(submitPostBtn, false);
            }
        });
    }

    // ── Feed Interactions (Likes, Comments, Deletion) ─────────────
    postsFeed.addEventListener('click', async (e) => {
        const likeBtn = e.target.closest('.like-action-btn');
        const commentToggle = e.target.closest('.comment-toggle-btn');
        const submitComment = e.target.closest('.submit-comment-btn');
        const commentDelete = e.target.closest('.comment-delete-trigger');
        const authorDelete = e.target.closest('.delete-post-btn');
        const adminDelete = e.target.closest('.btn-delete');
        const adminPin = e.target.closest('.btn-pin');
        const flagBtn = e.target.closest('.flag-action-btn');

        // Like Toggle
        if (likeBtn) {
            if (!currentUser) {
                showToast('You must log in to like posts!', 'warning');
                return;
            }
            const postId = likeBtn.dataset.postId;
            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'like_post', post_id: postId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const span = likeBtn.querySelector('span');
                    const svg = likeBtn.querySelector('svg');
                    span.textContent = data.likes_count;
                    if (data.state === 'liked') {
                        likeBtn.classList.add('liked');
                        svg.setAttribute('fill', '#fe6fbe');
                        svg.setAttribute('stroke', '#fe6fbe');
                    } else {
                        likeBtn.classList.remove('liked');
                        svg.setAttribute('fill', 'none');
                        const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
                        svg.setAttribute('stroke', isDarkTheme ? '#fff' : '#000');
                    }
                }
            } catch (err) {
                // Ignore silent network failure
            }
        }

        // Comments Toggle
        if (commentToggle) {
            const postId = commentToggle.dataset.postId;
            const sect = document.getElementById(`comments-${postId}`);
            if (sect) {
                const isHidden = (sect.style.display === 'none');
                sect.style.display = isHidden ? 'block' : 'none';
            }
        }

        // Submit Comment
        if (submitComment) {
            const postId = submitComment.dataset.postId;
            const inputField = postsFeed.querySelector(`.comment-input-box[data-post-id="${postId}"]`);
            const content = inputField ? inputField.value.trim() : '';

            if (content === '') return;

            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_comment',
                        post_id: postId,
                        content: content
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to post comment.', 'error');
            }
        }

        // Delete Comment (Author or Admin)
        if (commentDelete) {
            if (!confirm('Are you sure you want to delete this comment?')) return;
            const commentId = commentDelete.dataset.commentId;
            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_comment', comment_id: commentId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to delete comment.', 'error');
            }
        }

        // Delete Post (Author or Admin)
        if (authorDelete || adminDelete) {
            if (!confirm('Are you sure you want to delete this post? This cannot be undone.')) return;
            const postId = (authorDelete || adminDelete).dataset.postId;
            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_post', post_id: postId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Failed to delete post.', 'error');
            }
        }

        // Admin Toggle Pin
        if (adminPin) {
            const action = adminPin.dataset.action;
            const postId = adminPin.dataset.postId;
            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: action, post_id: postId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Action failed.', 'error');
            }
        }

        // Open Report Dialog
        if (flagBtn) {
            if (!currentUser) {
                showToast('You must log in to flag posts!', 'warning');
                return;
            }
            const postId = flagBtn.dataset.postId;
            if (reportPostId) reportPostId.value = postId;
            if (reportCommentId) reportCommentId.value = '';
            if (reportModal) reportModal.style.display = 'flex';
        }
    });

    // ── Bind modal closing ──────────────────────────────────────
    if (closeReportModalBtn) closeReportModalBtn.addEventListener('click', () => { reportModal.style.display = 'none'; });
    if (cancelReportBtn) cancelReportBtn.addEventListener('click', () => { reportModal.style.display = 'none'; });
    if (reportModal) {
        reportModal.addEventListener('click', (e) => {
            if (e.target === reportModal) reportModal.style.display = 'none';
        });
    }

    // ── Submit Report ───────────────────────────────────────────
    if (submitReportBtn) {
        submitReportBtn.addEventListener('click', async () => {
            const postId = reportPostId ? reportPostId.value : '';
            const commentId = reportCommentId ? reportCommentId.value : '';
            const reason = reportReason.value;
            const details = reportDetails.value.trim();

            if (reason === '') return;

            setButtonLoading(submitReportBtn, true);

            try {
                const res = await fetch('ajax_community.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'report_item',
                        post_id: postId || undefined,
                        comment_id: commentId || undefined,
                        reason: reason,
                        details: details
                    })
                });

                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    reportModal.style.display = 'none';
                    if (reportDetails) reportDetails.value = '';
                    loadFeed();
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Report submission failed.', 'error');
            } finally {
                setButtonLoading(submitReportBtn, false);
            }
        });
    }

    // Initial Load
    loadFeed();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initScrollRevealAndCounters();
        initChatPage();
        initAdminDashboard();
        initHomepage();
        initCommunityHub();
    });
} else {
    initScrollRevealAndCounters();
    initChatPage();
    initAdminDashboard();
    initHomepage();
    initCommunityHub();
}
