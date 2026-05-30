(function () {
    const STORAGE_KEY = 'anybuddy-theme';

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    const socialIcons = [
        { light: 'images/facebook.png', dark: 'images/facebook-dark.png' },
        { light: 'images/twitter.png', dark: 'images/twitter-dark.png' },
        { light: 'images/instagram.png', dark: 'images/instagram-dark.png' }
    ];

    // ── Modular Header & Footer Templates ──
    function renderHeaderAndFooter() {
        const navbar = document.querySelector('.navbar');
        if (navbar) {
            navbar.innerHTML = `
                <a href="homepage.html" class="navlogo">
                    <img src="images/AnyBuddy LOGO.png" alt="AnyBuddy Logo" width="40" height="40">
                    <h2>AnyBuddy</h2>
                </a>
                <div class="navlinks">
                    <a href="marketplace.html" class="theme-nav-link">Marketplace</a>
                    <a href="about.html" class="theme-nav-link">About</a>
                    <a href="chat.html" class="theme-nav-link auth-only-logged-in" id="navChatLink" style="display: none;">Chat</a>
                    <a href="login.html" class="theme-nav-link auth-only-logged-out" id="navLoginLink">Login</a>
                    <a href="signup.html" class="theme-nav-link auth-only-logged-out" id="navSignupLink">Sign up</a>
                    <a class="bab-btn" href="become-buddy.html">Become a Buddy</a>
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                        <img class="theme-toggle-icon" src="images/light-mode.png" alt="Switch to dark mode">
                    </button>
                    <!-- Notification Bell Container -->
                    <div class="nav-notification-container auth-only-logged-in" style="display: none; margin-right: 0.5rem;">
                        <button type="button" class="nav-notification-btn" id="navNotificationBtn" aria-label="Notifications">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-bell" style="vertical-align: middle;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <span class="notification-badge" id="navNotificationBadge" style="display: none;">0</span>
                        </button>
                        <div class="notification-dropdown" id="navNotificationDropdown">
                            <div class="notification-dropdown-header">
                                <h4>Notifications</h4>
                                <button type="button" class="mark-all-read-btn" id="markAllReadBtn">Mark all read</button>
                            </div>
                            <div class="notification-dropdown-body" id="navNotificationList">
                                <div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">No new notifications</div>
                            </div>
                            <div class="notification-dropdown-footer">
                                <a href="bookings.html">View My Bookings</a>
                            </div>
                        </div>
                    </div>
                    <div class="nav-user-link auth-only-logged-in" id="navUserBtn" style="display: none; cursor: pointer; position: relative;">
                        <img class="nav-user-icon" src="images/user-light.png" alt="User">
                        <span class="nav-avatar-status-badge" id="navAvatarStatus" style="display: none;"></span>
                    </div>
                </div>
                <button type="button" class="burger-toggle" id="burgerToggle" aria-label="Toggle menu">
                    <span></span><span></span><span></span>
                </button>
            `;
        }

        const footer = document.querySelector('.footer');
        if (footer) {
            footer.innerHTML = `
                <div class="media-icons">
                    <div class="footer-logo">
                        <img src="images/AnyBuddy LOGO.png" alt="AnyBuddy Logo" width="40" height="40">
                        <h3>AnyBuddy</h3>
                    </div>
                    <ul class="social-media-links">
                        <li><a href="#"><img src="images/facebook.png" alt="Facebook"></a></li>
                        <li><a href="#"><img src="images/twitter.png" alt="Twitter"></a></li>
                        <li><a href="#"><img src="images/instagram.png" alt="Instagram"></a></li>
                    </ul>
                </div>
                <div class="platform-links">
                    <h3>Platform</h3>
                    <ul>
                        <li><a href="marketplace.html">Marketplace</a></li>
                        <li><a href="about.html">About</a></li>
                        <li><a href="login.html" class="auth-only-logged-out">Login</a></li>
                        <li><a href="signup.html" class="auth-only-logged-out">Sign up</a></li>
                    </ul>
                </div>
                <div class="legal">
                    <h3>Legal</h3>
                    <ul>
                        <li><a href="#" class="legal-link" data-modal="legal">Legal Policy</a></li>
                        <li><a href="#" class="legal-link" data-modal="help">Help & Support</a></li>
                        <li><a href="#" class="legal-link" data-modal="safety">Trust & Safety</a></li>
                    </ul>
                </div>
            `;
        }
    }

    function updateIcons(dark) {
        document.querySelectorAll('.theme-toggle-icon').forEach(function (icon) {
            icon.src = dark ? 'images/dark-mode.png' : 'images/light-mode.png';
            icon.alt = dark ? 'Switch to light mode' : 'Switch to dark mode';
        });

        document.querySelectorAll('.nav-user-icon').forEach(function (icon) {
            var user = null;
            try {
                user = JSON.parse(localStorage.getItem('ab_user') || 'null');
            } catch (e) {}
            if (user && user.avatar_url) {
                icon.src = user.avatar_url;
            } else {
                icon.src = dark ? 'images/user-dark.png' : 'images/user-light.png';
            }
        });

        var navAvatarStatus = document.getElementById('navAvatarStatus');
        if (navAvatarStatus) {
            var currentStatus = localStorage.getItem('ab_presence_status') || 'online';
            var dotCls = 'presence-dot--online';
            if (currentStatus === 'offline') {
                dotCls = 'presence-dot--offline';
            } else if (currentStatus === 'invisible') {
                dotCls = 'presence-dot--invisible';
            }
            navAvatarStatus.className = 'nav-avatar-status-badge ' + dotCls;
            navAvatarStatus.style.display = 'block';
        }

        document.querySelectorAll('.social-media-links img').forEach(function (img) {
            const src = img.getAttribute('src') || '';
            socialIcons.forEach(function (pair) {
                if (src.includes(pair.light) || src.includes(pair.dark)) {
                    img.src = dark ? pair.dark : pair.light;
                }
            });
        });
    }

    function setTheme(dark) {
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
        updateIcons(dark);
    }

    const saved = localStorage.getItem(STORAGE_KEY);
    setTheme(saved === 'dark' || saved !== 'light');

    // ── Load Google Noto Emoji dynamically ──
    if (!document.getElementById('google-fonts-noto-emoji')) {
        const link = document.createElement('link');
        link.id = 'google-fonts-noto-emoji';
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=Noto+Emoji:wght@400;700&display=swap';
        document.head.appendChild(link);
    }

    // ── Spawn Floating Background Emojis ──
    function spawnFloatingEmojis() {
        let bg = document.querySelector('.floating-emojis-bg');
        if (!bg) {
            bg = document.createElement('div');
            bg.className = 'floating-emojis-bg';
            document.body.appendChild(bg);
        } else {
            bg.innerHTML = ''; // reset to prevent duplications
        }
        const emojiPool = ['🤝', '💬', '🎮', '🎨', '🎵', '🛡️', '🍿', '☕', '🔨', '🥊', '🎭', '🥳', '✨', '🥰', '🚀', '💡', '🎉', '🌟', '🍀', '🧸', '🎈', '🐱', '🐶', '🍕', '🍔', '🍩', '🥑', '🌮', '❤️', '🔥'];
        const count = 30;
        for (let i = 0; i < count; i++) {
            const span = document.createElement('span');
            span.className = 'floating-emoji';
            span.innerText = emojiPool[Math.floor(Math.random() * emojiPool.length)];
            
            span.style.left = `${Math.random() * 95}vw`;
            span.style.top = `${Math.random() * 90}vh`;
            span.style.animationDelay = `${Math.random() * -25}s`;
            span.style.fontSize = `${1.8 + Math.random() * 1.2}rem`;
            
            const duration = 20 + Math.random() * 15;
            span.style.animationDuration = `${duration}s`;
            
            bg.appendChild(span);
        }
    }

    // ── Setup Burger Drawer Menu (Mobile and Desktop) ──
    function setupBurgerDrawer() {
        const burgerBtn = document.getElementById('burgerToggle');
        if (!burgerBtn) return;

        let drawer = document.querySelector('.more-options-drawer');
        if (!drawer) {
            drawer = document.createElement('div');
            drawer.className = 'more-options-drawer';
            
            drawer.innerHTML = `
                <div class="drawer-header">
                    <h3>Menu</h3>
                    <button class="drawer-close" id="drawerClose">&times;</button>
                </div>
                <div class="drawer-menu-links">
                    <div class="mobile-only-nav">
                        <a href="marketplace.html">Marketplace</a>
                        <a href="about.html">About</a>
                        <a href="login.html" class="auth-only-logged-out">Login</a>
                        <a href="signup.html" class="auth-only-logged-out">Sign up</a>
                        <a class="bab-btn auth-only-logged-out" href="become-buddy.html" style="margin-top:0.5rem; text-align:center; color:#fff !important; background:var(--accent);">Become a Buddy</a>
                        
                        <!-- Logged-in mobile options -->
                        <a href="#" class="auth-only-logged-in btn-view-profile-mobile" style="display: none;">👤 View My Profile</a>
                        <a href="#" class="auth-only-logged-in btn-edit-profile-mobile" style="display: none;">✏️ Edit Profile</a>
                        <a href="bookings.html" class="auth-only-logged-in" style="display: none;">📅 My Bookings</a>
                        <a href="chat.html" class="auth-only-logged-in" style="display: none;">💬 Chat</a>
                        <a href="marketplace.html?view=favourites" class="auth-only-logged-in" style="display: none;">❤️ Favourites</a>
                        <a href="become-buddy.html" class="auth-only-logged-in btn-become-buddy-mobile" style="display: none; margin-top:0.5rem; text-align:center; color:#fff !important; background:var(--accent); border-radius: 20px; padding: 0.5rem 1rem;">Become a Buddy</a>
                        <a href="#" class="auth-only-logged-in btn-logout-mobile" style="display: none; color: #ef4444 !important;">🚪 Logout</a>
                    </div>
                    <div class="drawer-divider mobile-only-nav"></div>
                    <h4 style="margin:0 0 0.5rem 0.5rem; opacity:0.6; font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em;">Resources</h4>
                    <a href="#" class="legal-link" data-modal="legal">⚖️ Legal Policy</a>
                    <a href="#" class="legal-link" data-modal="help">💬 Help & Support</a>
                    <a href="#" class="legal-link" data-modal="safety">🛡️ Trust & Safety</a>
                </div>
                <div class="drawer-footer">
                    <button type="button" class="theme-toggle" id="themeToggleDrawer">
                        <img class="theme-toggle-icon" src="images/light-mode.png" alt="Theme">
                    </button>
                </div>
            `;
            document.body.appendChild(drawer);
        }

        const closeBtn = document.getElementById('drawerClose');
        const themeToggleDrawer = document.getElementById('themeToggleDrawer');

        // Toggle Drawer open/close
        burgerBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            burgerBtn.classList.toggle('active');
            drawer.classList.toggle('active');
        });

        const closeDrawer = () => {
            burgerBtn.classList.remove('active');
            drawer.classList.remove('active');
        };

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        if (themeToggleDrawer) {
            themeToggleDrawer.addEventListener('click', function (e) {
                const mainToggle = document.getElementById('themeToggle');
                if (mainToggle) {
                    mainToggle.dispatchEvent(new MouseEvent('click', {
                        clientX: e.clientX,
                        clientY: e.clientY
                    }));
                }
            });
        }

        document.addEventListener('click', function (e) {
            if (!drawer.contains(e.target) && !burgerBtn.contains(e.target)) {
                closeDrawer();
            }
        });
    }

    // ── Custom Info Modals ──
    const modalContents = {
        legal: {
            title: "⚖️ Legal Policy & Guidelines",
            body: `
                <div style="max-height: 400px; overflow-y: auto; padding-right: 0.5rem; line-height: 1.6; font-size: 0.9rem;">
                    <h4 style="margin-top:0; color:var(--accent);">1. Terms of Service</h4>
                    <p>By using the AnyBuddy platform, you agree to the CvSU community standards and academic guidelines. AnyBuddy is a platform facilitating safe freelance buddy gigs in our local community.</p>
                    <h4 style="color:var(--accent); margin-top:1.5rem;">2. Privacy Policy</h4>
                    <p>We respect your privacy. Your personal information, contact emails, and chat messages are encrypted and only accessible to authorized platform users to ensure safe bookings.</p>
                    <h4 style="color:var(--accent); margin-top:1.5rem;">3. Cancellation & Refund</h4>
                    <p>Bookings cancelled at least 24 hours in advance receive a 100% refund. Cancellations within 12 hours may incur a partial processing fee to support the buddy's reserved slot.</p>
                </div>
            `
        },
        help: {
            title: "💬 Help & Support Center",
            body: `
                <div style="max-height: 400px; overflow-y: auto; padding-right: 0.5rem; line-height: 1.6; font-size: 0.9rem;">
                    <h4 style="margin-top:0; color:var(--accent);">Frequently Asked Questions (FAQ)</h4>
                    <div style="margin-bottom:1rem; border-bottom:1px solid var(--border-glass); padding-bottom:0.75rem;">
                        <strong>Q: How do I book a buddy?</strong><br>
                        <span>A: Log in, find a buddy on the marketplace, visit their profile, click "Book Now", and complete checkout.</span>
                    </div>
                    <div style="margin-bottom:1rem; border-bottom:1px solid var(--border-glass); padding-bottom:0.75rem;">
                        <strong>Q: Is there any registration fee?</strong><br>
                        <span>A: No, registration for clients and buddies is completely free. You only pay for booked buddy services.</span>
                    </div>
                    <div style="margin-bottom:1rem; padding-bottom:0.5rem;">
                        <strong>Q: How do I change my avatar or bio details?</strong><br>
                        <span>A: Log in, click on your profile icon in the top-right navbar, select "Edit Profile" from the dropdown, update your info, and save.</span>
                    </div>
                    <h4 style="color:var(--accent); margin-top:1.5rem;">Contact Support</h4>
                    <p>Have further questions? Reach out to the CvSU AnyBuddy student helpline:<br>
                    ✉️ <a href="mailto:support@anybuddy.cvsu.edu.ph" style="color:var(--accent); font-weight:700; text-decoration:none;">support@anybuddy.cvsu.edu.ph</a></p>
                </div>
            `
        },
        safety: {
            title: "🛡️ Trust & Safety Standards",
            body: `
                <div style="max-height: 400px; overflow-y: auto; padding-right: 0.5rem; line-height: 1.6; font-size: 0.9rem;">
                    <h4 style="margin-top:0; color:var(--accent);">1. Identity Verification</h4>
                    <p>Look for the green checkmark badge (<span style="color:#00d2ff; font-weight:700;">✓</span>) on buddy profiles, which indicates that their CvSU student identity and credentials have been verified by the AnyBuddy safety team.</p>
                    <h4 style="color:var(--accent); margin-top:1.5rem;">2. Report Abuse or Violations</h4>
                    <p>If you encounter inappropriate behavior, harassment, or safety violations, click the red <strong>❗ Report User</strong> button on their profile page immediately. Our trust team reviews reports within 12 hours.</p>
                    <h4 style="color:var(--accent); margin-top:1.5rem;">3. Secure Transactions</h4>
                    <p>Always complete payments inside the AnyBuddy checkout system. We hold funds securely until booking execution to protect both clients and buddies.</p>
                </div>
            `
        }
    };

    function setupModals() {
        document.addEventListener('click', function (e) {
            const modalLink = e.target.closest('.legal-link');
            if (!modalLink) return;

            e.preventDefault();
            const type = modalLink.dataset.modal;
            const content = modalContents[type];
            if (!content) return;

            // Remove existing modal if any
            let oldModal = document.querySelector('.info-modal-overlay');
            if (oldModal) oldModal.remove();

            const overlay = document.createElement('div');
            overlay.className = 'info-modal-overlay';
            overlay.innerHTML = `
                <div class="info-modal-card">
                    <button class="info-modal-close" id="infoModalClose">&times;</button>
                    <h3 class="info-modal-title" style="margin-top:0; margin-bottom:1.5rem; font-size:1.4rem; color:var(--text-primary); border-bottom:1px solid var(--border-glass); padding-bottom:0.75rem;">${content.title}</h3>
                    <div class="info-modal-body" style="color:var(--text-secondary);">${content.body}</div>
                </div>
            `;
            document.body.appendChild(overlay);

            setTimeout(() => overlay.classList.add('active'), 10);

            const closeBtn = overlay.querySelector('#infoModalClose');
            const closeModal = () => {
                overlay.classList.remove('active');
                overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
            };

            closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', (ev) => {
                if (ev.target === overlay) closeModal();
            });
        });
    }

    // ── Floating BuddyBot Chatbot ──
    function setupBuddyBot() {
        if (document.getElementById('buddybotLauncher')) return;

        // Inject launcher button
        const launcher = document.createElement('div');
        launcher.id = 'buddybotLauncher';
        launcher.className = 'buddybot-launcher';
        launcher.innerHTML = `<span>🤖</span>`;
        document.body.appendChild(launcher);

        // Inject chatbot window
        const windowDiv = document.createElement('div');
        windowDiv.id = 'buddybotWindow';
        windowDiv.className = 'buddybot-window';
        windowDiv.innerHTML = `
            <div class="buddybot-header">
                <div class="buddybot-avatar">🤖</div>
                <div>
                    <div class="buddybot-title">BuddyBot</div>
                    <div class="buddybot-status">online</div>
                </div>
                <button type="button" class="buddybot-close" id="buddybotClose">&times;</button>
            </div>
            <div class="buddybot-messages" id="buddybotMessages">
                <div class="buddybot-message incoming">
                    Hi there! I'm BuddyBot 🤖, your friendly AnyBuddy assistant. How can I help you today?
                </div>
            </div>
            <div class="buddybot-quick-replies" id="buddybotQuickReplies">
                <button type="button" class="buddybot-quick" data-reply="book">📅 How to book a buddy?</button>
                <button type="button" class="buddybot-quick" data-reply="become"> Become a Buddy</button>
                <button type="button" class="buddybot-quick" data-reply="safety">🛡️ Is it safe?</button>
                <button type="button" class="buddybot-quick" data-reply="contact">✉️ Contact Support</button>
            </div>
            <form class="buddybot-input-area" id="buddybotInputForm">
                <input type="text" id="buddybotInput" placeholder="Type a message..." required autocomplete="off">
                <button type="submit" class="buddybot-send">Send</button>
            </form>
        `;
        document.body.appendChild(windowDiv);

        const botMessages = windowDiv.querySelector('#buddybotMessages');
        const inputForm = windowDiv.querySelector('#buddybotInputForm');
        const inputField = windowDiv.querySelector('#buddybotInput');
        const closeBtn = windowDiv.querySelector('#buddybotClose');
        const quickReplies = windowDiv.querySelector('#buddybotQuickReplies');

        // Toggle chat window open/close
        launcher.addEventListener('click', () => {
            windowDiv.classList.add('active');
            launcher.classList.add('hidden');
        });

        closeBtn.addEventListener('click', () => {
            windowDiv.classList.remove('active');
            launcher.classList.remove('hidden');
        });

        // Add message helper
        function addMessage(text, isOutgoing = false) {
            const msg = document.createElement('div');
            msg.className = `buddybot-message ${isOutgoing ? 'outgoing' : 'incoming'}`;
            msg.textContent = text;
            botMessages.appendChild(msg);
            botMessages.scrollTop = botMessages.scrollHeight;
        }

        // Show typing indicator
        function showTypingIndicator() {
            const typing = document.createElement('div');
            typing.className = 'buddybot-message incoming buddybot-typing';
            typing.innerHTML = `<span></span><span></span><span></span>`;
            botMessages.appendChild(typing);
            botMessages.scrollTop = botMessages.scrollHeight;
            return typing;
        }

        // Handle bot responses
        const botResponses = {
            book: "Booking is easy! Go to the Marketplace, browse the profiles, click 'View Profile', and click 'Book Now'. Remember to log in first!",
            become: "Want to offer your skills? Click the 'Become a Buddy' button in the navbar or visit become-buddy.html to fill out your profile!",
            safety: "Absolutely! We verify CvSU buddy identities. Look for the green checkmark badge (✓) on profiles. You can also report any concern using the 'Report' button.",
            contact: "You can find more help in the Help & Support menu or email our team directly at support@anybuddy.cvsu.edu.ph"
        };

        function simulateReply(keyOrText) {
            const typing = showTypingIndicator();
            setTimeout(() => {
                typing.remove();
                const reply = botResponses[keyOrText] || "I'm not sure I understand that yet! 🤖\n\nI can help you with:\n📅 How to book a buddy\n💼 How to become a buddy\n🛡️ Safety & verification details\n✉️ Contacting support\n\nTry typing keywords like 'book', 'become', 'safety', or 'support', or click one of the quick replies above!";
                addMessage(reply, false);
            }, 1000);
        }

        // Bind quick replies
        quickReplies.addEventListener('click', (e) => {
            const btn = e.target.closest('.buddybot-quick');
            if (!btn) return;
            const replyKey = btn.dataset.reply;
            addMessage(btn.textContent, true);
            simulateReply(replyKey);
        });

        // Handle form submit
        inputForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const val = inputField.value.trim();
            if (!val) return;
            addMessage(val, true);
            inputField.value = '';

            const lower = val.toLowerCase();
            let replyKey = 'default';

            if (lower.includes('book') || lower.includes('rent') || lower.includes('find') || lower.includes('hire') || lower.includes('reserve')) {
                replyKey = 'book';
            } else if (lower.includes('become') || lower.includes('work') || lower.includes('skills') || lower.includes('earn') || lower.includes('apply')) {
                replyKey = 'become';
            } else if (lower.includes('safe') || lower.includes('safety') || lower.includes('trust') || lower.includes('scam') || lower.includes('legit') || lower.includes('verify')) {
                replyKey = 'safety';
            } else if (lower.includes('contact') || lower.includes('support') || lower.includes('help') || lower.includes('email') || lower.includes('phone') || lower.includes('complain') || lower.includes('problem')) {
                replyKey = 'contact';
            }

            simulateReply(replyKey);
        });
    }

    // ── Unified Easter Eggs ──
    function setupEasterEggs() {
        let inputBuffer = '';
        const maxBufferLength = 10;
        let isGameActive = false;

        document.addEventListener('keydown', (e) => {
            // Ignore if typing in input/textarea/select/editable elements
            const activeEl = document.activeElement;
            if (activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT' || activeEl.contentEditable === 'true')) {
                return;
            }

            // Prevent default page scroll for arrows and space if game is active
            if (isGameActive && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' '].includes(e.key)) {
                e.preventDefault();
            }
            
            // Only build buffer if it's a single key (ignore modifier keys)
            if (e.key.length === 1) {
                inputBuffer += e.key.toLowerCase();
                if (inputBuffer.length > maxBufferLength) {
                    inputBuffer = inputBuffer.slice(-maxBufferLength);
                }
                
                if (inputBuffer.endsWith('rick')) {
                    inputBuffer = ''; // reset buffer
                    triggerRickRoll();
                } else if (inputBuffer.endsWith('bird')) {
                    inputBuffer = ''; // reset buffer
                    triggerBirdGame();
                }
            }
        });
        
        function triggerRickRoll() {
            if (document.querySelector('.rickroll-overlay')) return;
            
            const overlay = document.createElement('div');
            overlay.className = 'rickroll-overlay';
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.width = '100vw';
            overlay.style.height = '100vh';
            overlay.style.background = 'rgba(0, 0, 0, 0.75)';
            overlay.style.backdropFilter = 'blur(15px)';
            overlay.style.webkitBackdropFilter = 'blur(15px)';
            overlay.style.zIndex = '99999';
            overlay.style.display = 'flex';
            overlay.style.flexDirection = 'column';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.5s ease';
            
            overlay.innerHTML = `
                <div class="rickroll-container" style="position: relative; width: 90%; max-width: 800px; aspect-ratio: 16/9; background: #000; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transform: scale(0.8); transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                    <button type="button" class="rickroll-close" style="position: absolute; top: 15px; right: 15px; background: rgba(0,0,0,0.6); color: #fff; border: 1px solid rgba(255,255,255,0.2); width: 36px; height: 36px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10; transition: all 0.25s ease;">&times;</button>
                    <video src="images/Rick Astley - Never Gonna Give You Up (Official Video) (4K Remaster) - Rick Astley (1080p, h264).mp4" autoplay controls style="width: 100%; height: 100%; object-fit: contain; border: none;"></video>
                </div>
                <div class="rickroll-subtitle" style="margin-top: 20px; color: #fff; font-family: 'Google Sans', sans-serif; font-size: 1.2rem; font-weight: bold; text-align: center; text-shadow: 0 2px 10px rgba(0,0,0,0.5); opacity: 0; transform: translateY(10px); transition: all 0.5s ease 0.3s; pointer-events: none;">
                    🕺 Never Gonna Give You Up! 🕺
                </div>
            `;
            
            document.body.appendChild(overlay);
            
            // Trigger animation
            setTimeout(() => {
                overlay.style.opacity = '1';
                overlay.querySelector('.rickroll-container').style.transform = 'scale(1)';
                overlay.querySelector('.rickroll-subtitle').style.opacity = '1';
                overlay.querySelector('.rickroll-subtitle').style.transform = 'translateY(0)';
            }, 50);
            
            const closeBtn = overlay.querySelector('.rickroll-close');
            const closeOverlay = () => {
                const videoEl = overlay.querySelector('video');
                if (videoEl) videoEl.pause();
                overlay.style.opacity = '0';
                overlay.querySelector('.rickroll-container').style.transform = 'scale(0.8)';
                overlay.querySelector('.rickroll-subtitle').style.opacity = '0';
                overlay.querySelector('.rickroll-subtitle').style.transform = 'translateY(10px)';
                setTimeout(() => overlay.remove(), 500);
            };
            
            closeBtn.addEventListener('click', closeOverlay);
            overlay.addEventListener('click', (ev) => {
                if (ev.target === overlay) closeOverlay();
            });
            
            // Hover styles for close button
            closeBtn.addEventListener('mouseenter', () => {
                closeBtn.style.background = '#ff4081';
                closeBtn.style.borderColor = '#ff4081';
                closeBtn.style.transform = 'scale(1.1)';
            });
            closeBtn.addEventListener('mouseleave', () => {
                closeBtn.style.background = 'rgba(0,0,0,0.6)';
                closeBtn.style.borderColor = 'rgba(255,255,255,0.2)';
                closeBtn.style.transform = 'scale(1)';
            });
        }

        function triggerBirdGame() {
            if (document.querySelector('.birdgame-overlay')) return;
            isGameActive = true;

            let bird = {
                x: 100,
                y: 280,
                radius: 12,
                velocity: 0,
                gravity: 0.02,
                lift: -1.5,
                angle: 0,
                wingPosition: 0
            };

            let pipes = [];
            const gap = 165;
            const pipeWidth = 52;
            const pipeSpacing = 280;
            let pipeSpeed = 0.8;
            let score = 0;
            let highScore = parseInt(localStorage.getItem('ab-bird-highscore') || '0', 10);
            let isPaused = false;
            let isGameOver = false;
            let hasStarted = false;
            let lastTime = 0;
            let animationFrameId = null;
            let soundEnabled = localStorage.getItem('ab-bird-sound') !== 'false';
            
            let canvas = null;
            let ctx = null;
            let stars = [];
            let particles = [];
            let audioCtx = null;

            // Inject styles dynamically if not already present
            if (!document.getElementById('bird-game-styles')) {
                const styleEl = document.createElement('style');
                styleEl.id = 'bird-game-styles';
                styleEl.innerHTML = `
                    .birdgame-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100vw;
                        height: 100vh;
                        background: rgba(0, 0, 0, 0.75);
                        backdrop-filter: blur(15px);
                        -webkit-backdrop-filter: blur(15px);
                        z-index: 99999;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        opacity: 0;
                        transition: opacity 0.4s ease;
                    }
                    .birdgame-overlay.active {
                        opacity: 1;
                    }
                    .birdgame-container {
                        position: relative;
                        width: 95%;
                        max-width: 520px;
                        background: var(--bg-modal);
                        border: 1px solid var(--border-glass);
                        border-radius: 24px;
                        padding: 20px;
                        box-shadow: 0 20px 50px rgba(0,0,0,0.5), 0 0 30px var(--border-modal);
                        transform: scale(0.85);
                        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        color: var(--text-primary);
                        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    }
                    .birdgame-overlay.active .birdgame-container {
                        transform: scale(1);
                    }
                    .bird-header {
                        display: flex;
                        width: 100%;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 12px;
                    }
                    .bird-title {
                        font-weight: 800;
                        font-size: 1.3rem;
                        margin: 0;
                        background: linear-gradient(135deg, var(--accent), #fe6fbe);
                        -webkit-background-clip: text;
                        -webkit-text-fill-color: transparent;
                        letter-spacing: -0.5px;
                    }
                    .bird-controls-top {
                        display: flex;
                        gap: 8px;
                        align-items: center;
                    }
                    .bird-btn-icon {
                        background: rgba(255, 255, 255, 0.1);
                        border: 1px solid var(--border-glass);
                        color: var(--text-primary);
                        width: 36px;
                        height: 36px;
                        border-radius: 50%;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 16px;
                        transition: all 0.2s ease;
                    }
                    .bird-btn-icon:hover {
                        background: var(--accent);
                        color: #fff;
                        transform: scale(1.05);
                    }
                    .bird-score-panel {
                        display: flex;
                        justify-content: space-between;
                        width: 100%;
                        background: rgba(0, 0, 0, 0.15);
                        border-radius: 12px;
                        padding: 10px 16px;
                        margin-bottom: 12px;
                        font-size: 0.95rem;
                        font-weight: 600;
                        border: 1px solid var(--border-glass);
                    }
                    .bird-score-item span {
                        color: var(--accent);
                        font-weight: 800;
                    }
                    .bird-canvas-container {
                        position: relative;
                        width: 100%;
                        max-width: 480px;
                        aspect-ratio: 480 / 560;
                        cursor: pointer;
                        overflow: hidden;
                        border-radius: 12px;
                        border: 2px solid var(--accent);
                        box-shadow: 0 0 15px rgba(0, 0, 0, 0.4);
                        margin: 0 auto;
                    }
                    #birdCanvas {
                        width: 100%;
                        height: 100%;
                        display: block;
                        background: #060a13;
                    }
                    .bird-action-area {
                        display: flex;
                        width: 100%;
                        justify-content: center;
                        gap: 12px;
                        margin-top: 12px;
                    }
                    .bird-btn-action {
                        padding: 10px 20px;
                        background: rgba(255, 255, 255, 0.05);
                        border: 1px solid var(--border-glass);
                        border-radius: 12px;
                        color: var(--text-primary);
                        font-size: 0.95rem;
                        font-weight: 600;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 6px;
                        transition: all 0.15s ease;
                    }
                    .bird-btn-action:hover {
                        background: var(--accent);
                        color: #fff;
                        box-shadow: 0 0 10px var(--accent);
                    }
                    .bird-legend {
                        margin-top: 12px;
                        font-size: 0.8rem;
                        opacity: 0.7;
                        text-align: center;
                        line-height: 1.4;
                    }
                `;
                document.head.appendChild(styleEl);
            }

            const overlay = document.createElement('div');
            overlay.className = 'birdgame-overlay';
            overlay.innerHTML = `
                <div class="birdgame-container">
                    <div class="bird-header">
                        <h3 class="bird-title">⚡ AnyBuddy Bird</h3>
                        <div class="bird-controls-top">
                            <button type="button" class="bird-btn-icon" id="birdSoundToggle" title="Toggle Sound">${soundEnabled ? '🔊' : '🔇'}</button>
                            <button type="button" class="bird-btn-icon" id="birdCloseBtn" title="Close Game" style="font-size:20px;">&times;</button>
                        </div>
                    </div>
                    
                    <div class="bird-score-panel">
                        <div class="bird-score-item">SCORE: <span id="birdScore">0</span></div>
                        <div class="bird-score-item">HIGH SCORE: <span id="birdHighScore">${highScore}</span></div>
                    </div>
                    
                    <div class="bird-canvas-container" id="birdCanvasContainer">
                        <canvas id="birdCanvas" width="480" height="560"></canvas>
                    </div>
                    
                    <div class="bird-action-area">
                        <button type="button" class="bird-btn-action" id="birdPauseBtn">⏸ Pause</button>
                    </div>
                    
                    <div class="bird-legend">
                        Click anywhere inside the console to flap!
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            // Trigger overlay fade-in
            setTimeout(() => {
                overlay.classList.add('active');
                isGameActive = true;
            }, 50);

            canvas = overlay.querySelector('#birdCanvas');
            ctx = canvas.getContext('2d');

            // Play synthesized sounds
            function playSound(type) {
                if (!soundEnabled) return;
                try {
                    if (!audioCtx) {
                        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                    }
                    if (audioCtx.state === 'suspended') {
                        audioCtx.resume();
                    }
                    
                    const osc = audioCtx.createOscillator();
                    const gainNode = audioCtx.createGain();
                    osc.connect(gainNode);
                    gainNode.connect(audioCtx.destination);
                    
                    const now = audioCtx.currentTime;
                    if (type === 'flap') {
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(200, now);
                        osc.frequency.exponentialRampToValueAtTime(550, now + 0.08);
                        gainNode.gain.setValueAtTime(0.12, now);
                        gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.08);
                        osc.start(now);
                        osc.stop(now + 0.08);
                    } else if (type === 'score') {
                        osc.type = 'triangle';
                        osc.frequency.setValueAtTime(587.33, now); // D5
                        osc.frequency.setValueAtTime(880.00, now + 0.08); // A5
                        gainNode.gain.setValueAtTime(0.1, now);
                        gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.18);
                        osc.start(now);
                        osc.stop(now + 0.18);
                    } else if (type === 'crash') {
                        osc.type = 'sawtooth';
                        osc.frequency.setValueAtTime(320, now);
                        osc.frequency.linearRampToValueAtTime(60, now + 0.35);
                        gainNode.gain.setValueAtTime(0.2, now);
                        gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.35);
                        osc.start(now);
                        osc.stop(now + 0.35);
                    }
                } catch (e) {
                    console.warn('Audio synthesis failed:', e);
                }
            }

            function initStars() {
                stars = [];
                for (let i = 0; i < 40; i++) {
                    stars.push({
                        x: Math.random() * 480,
                        y: Math.random() * 560,
                        size: 0.5 + Math.random() * 1.5,
                        speed: 0.2 + Math.random() * 0.6,
                        brightness: 0.3 + Math.random() * 0.7
                    });
                }
            }

            function spawnFlapParticles(x, y) {
                for (let i = 0; i < 6; i++) {
                    const angle = Math.PI + (Math.random() - 0.5) * 0.6; // blow backwards
                    const spd = 1 + Math.random() * 2;
                    particles.push({
                        x: x - bird.radius,
                        y: y,
                        vx: Math.cos(angle) * spd,
                        vy: Math.sin(angle) * spd + (Math.random() - 0.5),
                        life: 1.0,
                        decay: 0.04 + Math.random() * 0.04,
                        color: '#ffe600',
                        size: 2 + Math.random() * 2
                    });
                }
            }

            function spawnCrashParticles(x, y) {
                const colors = ['#ffe600', '#ff00aa', '#00f6ff', '#ff6a00'];
                for (let i = 0; i < 25; i++) {
                    const angle = Math.random() * Math.PI * 2;
                    const spd = 2 + Math.random() * 4;
                    particles.push({
                        x: x,
                        y: y,
                        vx: Math.cos(angle) * spd,
                        vy: Math.sin(angle) * spd,
                        life: 1.0,
                        decay: 0.02 + Math.random() * 0.03,
                        color: colors[Math.floor(Math.random() * colors.length)],
                        size: 3 + Math.random() * 3
                    });
                }
            }

            function initGame() {
                bird.x = 100;
                bird.y = 280;
                bird.velocity = 0;
                bird.angle = 0;
                bird.wingPosition = 0;
                pipes = [];
                score = 0;
                pipeSpeed = 0.8;
                isPaused = false;
                isGameOver = false;
                hasStarted = false;
                particles = [];
                initStars();
                spawnPipe(680); // first pipe starts further away (delayed arrival)

                const scoreEl = document.getElementById('birdScore');
                if (scoreEl) scoreEl.textContent = score;
                const pauseBtn = document.getElementById('birdPauseBtn');
                if (pauseBtn) pauseBtn.innerHTML = '⏸ Pause';
                if (canvas) canvas.style.borderColor = '';
            }

            function spawnPipe(customX) {
                const minHeight = 50;
                const maxHeight = 560 - gap - minHeight - 50;
                const topHeight = minHeight + Math.floor(Math.random() * maxHeight);
                pipes.push({
                    x: customX !== undefined ? customX : 480,
                    topHeight: topHeight,
                    width: pipeWidth,
                    passed: false
                });
            }

            function flapBird() {
                bird.velocity = bird.lift;
                spawnFlapParticles(bird.x, bird.y);
                playSound('flap');
            }

            function updateGame() {
                if (!hasStarted || isPaused || isGameOver) return;

                // Update pipe speed dynamically as score increases (starts at 0.8, increases by 0.08 per point, capped at 2.0)
                pipeSpeed = Math.min(2.0, 0.8 + score * 0.08);

                // Bird Physics
                bird.velocity += bird.gravity;
                bird.y += bird.velocity;
                
                // Tilt angle calculation
                bird.angle = Math.min(Math.PI / 3, Math.max(-Math.PI / 8, bird.velocity * 0.08));
                bird.wingPosition = Math.sin(performance.now() * 0.03);

                // Boundary collision (ground and ceiling)
                if (bird.y + bird.radius >= 560) {
                    bird.y = 560 - bird.radius;
                    triggerGameOver();
                    return;
                }
                if (bird.y - bird.radius <= 0) {
                    bird.y = bird.radius;
                    bird.velocity = 0; // bump ceiling, slide down
                }

                // Update stars background
                stars.forEach(star => {
                    star.x -= star.speed;
                    if (star.x < 0) {
                        star.x = 480;
                        star.y = Math.random() * 560;
                    }
                });

                // Update particles
                for (let i = particles.length - 1; i >= 0; i--) {
                    const p = particles[i];
                    p.x += p.vx;
                    p.y += p.vy;
                    p.vy += 0.04; // gravity pull on particles
                    p.life -= p.decay;
                    if (p.life <= 0) {
                        particles.splice(i, 1);
                    }
                }

                // Update pipes
                for (let i = pipes.length - 1; i >= 0; i--) {
                    const pipe = pipes[i];
                    pipe.x -= pipeSpeed; // pipe speed

                    // Collision Check
                    if (bird.x + bird.radius > pipe.x && bird.x - bird.radius < pipe.x + pipe.width) {
                        if (bird.y - bird.radius < pipe.topHeight || bird.y + bird.radius > pipe.topHeight + gap) {
                            triggerGameOver();
                            return;
                        }
                    }

                    // Score Check
                    if (!pipe.passed && pipe.x + pipe.width < bird.x) {
                        pipe.passed = true;
                        score++;
                        const scoreEl = document.getElementById('birdScore');
                        if (scoreEl) scoreEl.textContent = score;

                        if (score > highScore) {
                            highScore = score;
                            localStorage.setItem('ab-bird-highscore', highScore);
                            const highEl = document.getElementById('birdHighScore');
                            if (highEl) highEl.textContent = highScore;
                        }
                        playSound('score');
                    }

                    // Remove off-screen pipes
                    if (pipe.x + pipe.width < 0) {
                        pipes.splice(i, 1);
                    }
                }

                // Spawning new pipes based on space
                if (pipes.length > 0) {
                    const lastPipe = pipes[pipes.length - 1];
                    if (lastPipe.x < 480 - pipeSpacing) {
                        spawnPipe();
                    }
                }
            }

            function triggerGameOver() {
                isGameOver = true;
                spawnCrashParticles(bird.x, bird.y);
                playSound('crash');
                if (canvas) {
                    canvas.style.borderColor = '#ff0055';
                    setTimeout(() => {
                        const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#00d2ff';
                        if (canvas) canvas.style.borderColor = accentColor;
                    }, 500);
                }
            }

            function drawGame() {
                if (!ctx) return;
                
                // Clear Canvas with sleek deep space background
                ctx.fillStyle = '#060a13';
                ctx.fillRect(0, 0, 480, 560);

                // Draw parallax stars
                stars.forEach(star => {
                    ctx.fillStyle = `rgba(255, 255, 255, ${star.brightness})`;
                    ctx.fillRect(star.x, star.y, star.size, star.size);
                });

                const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#00d2ff';

                // Draw pipes
                pipes.forEach(pipe => {
                    ctx.save();
                    ctx.shadowBlur = 10;
                    ctx.shadowColor = '#00f6ff'; // Neon Cyan
                    ctx.strokeStyle = '#00f6ff';
                    ctx.lineWidth = 2.5;
                    ctx.fillStyle = 'rgba(0, 246, 255, 0.08)';

                    // Top Pipe Rect
                    ctx.beginPath();
                    ctx.rect(pipe.x, 0, pipe.width, pipe.topHeight);
                    ctx.fill();
                    ctx.stroke();

                    // Top Pipe Lip
                    ctx.fillStyle = '#060a13';
                    ctx.beginPath();
                    ctx.rect(pipe.x - 3, pipe.topHeight - 16, pipe.width + 6, 16);
                    ctx.fill();
                    ctx.stroke();

                    // Bottom Pipe Rect
                    const bottomY = pipe.topHeight + gap;
                    const bottomHeight = 560 - bottomY;
                    ctx.beginPath();
                    ctx.rect(pipe.x, bottomY, pipe.width, bottomHeight);
                    ctx.fill();
                    ctx.stroke();

                    // Bottom Pipe Lip
                    ctx.beginPath();
                    ctx.rect(pipe.x - 3, bottomY, pipe.width + 6, 16);
                    ctx.fill();
                    ctx.stroke();

                    ctx.restore();
                });

                // Draw particles
                particles.forEach(p => {
                    ctx.save();
                    ctx.globalAlpha = p.life;
                    ctx.shadowBlur = 8;
                    ctx.shadowColor = p.color;
                    ctx.fillStyle = p.color;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.restore();
                });

                // Draw Bird
                ctx.save();
                ctx.translate(bird.x, bird.y);
                ctx.rotate(bird.angle);
                
                // Bird neon body
                ctx.shadowBlur = 12;
                ctx.shadowColor = '#ffe600';
                ctx.fillStyle = '#ffe600';
                ctx.beginPath();
                ctx.arc(0, 0, bird.radius, 0, Math.PI * 2);
                ctx.fill();

                // Beak
                ctx.shadowBlur = 8;
                ctx.shadowColor = '#ff6a00';
                ctx.fillStyle = '#ff6a00';
                ctx.beginPath();
                ctx.moveTo(bird.radius - 2, -4);
                ctx.lineTo(bird.radius + 7, 0);
                ctx.lineTo(bird.radius - 2, 4);
                ctx.closePath();
                ctx.fill();

                // Wing (Neon pink)
                ctx.shadowBlur = 8;
                ctx.shadowColor = '#ff00aa';
                ctx.fillStyle = '#ff00aa';
                ctx.beginPath();
                ctx.ellipse(-6, 2, 8, 5, -Math.PI / 6 + (bird.wingPosition * 0.1), 0, Math.PI * 2);
                ctx.fill();

                // Eye
                ctx.fillStyle = '#000';
                ctx.shadowBlur = 0;
                ctx.beginPath();
                ctx.arc(4, -3, 2, 0, Math.PI * 2);
                ctx.fill();

                ctx.restore();

                // Draw HUD Overlays
                if (!hasStarted) {
                    ctx.fillStyle = 'rgba(6, 10, 19, 0.82)';
                    ctx.fillRect(0, 0, 480, 560);

                    ctx.font = 'bold 20px system-ui';
                    ctx.fillStyle = '#fff';
                    ctx.textAlign = 'center';
                    ctx.fillText('RETRO NEON BIRD', 240, 210);

                    ctx.font = '14px system-ui';
                    ctx.fillStyle = accentColor;
                    ctx.fillText('Click anywhere inside card to FLAP & START', 240, 260);

                    ctx.font = '12px system-ui';
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.5)';
                    ctx.fillText('Navigate through neon pipes without hitting them!', 240, 310);
                } else if (isPaused) {
                    ctx.fillStyle = 'rgba(6, 10, 19, 0.7)';
                    ctx.fillRect(0, 0, 480, 560);

                    ctx.font = 'bold 22px system-ui';
                    ctx.fillStyle = accentColor;
                    ctx.textAlign = 'center';
                    ctx.fillText('PAUSED', 240, 250);

                    ctx.font = '14px system-ui';
                    ctx.fillStyle = '#fff';
                    ctx.fillText('Click Pause button to resume', 240, 290);
                } else if (isGameOver) {
                    ctx.fillStyle = 'rgba(6, 10, 19, 0.85)';
                    ctx.fillRect(0, 0, 480, 560);

                    ctx.font = 'bold 26px system-ui';
                    ctx.fillStyle = '#ff0055';
                    ctx.textAlign = 'center';
                    ctx.fillText('GAME OVER', 240, 200);

                    ctx.font = 'bold 18px system-ui';
                    ctx.fillStyle = '#fff';
                    ctx.fillText(`Final Score: ${score}`, 240, 250);

                    ctx.font = '14px system-ui';
                    ctx.fillStyle = accentColor;
                    ctx.fillText('Click anywhere inside card to RESTART', 240, 300);
                }
            }

            function gameLoop(timestamp) {
                if (!isGameActive) return;

                if (!lastTime) lastTime = timestamp;
                
                // Keep rendering loop going for backdrop star scroll & particles
                if (hasStarted && !isPaused && !isGameOver) {
                    updateGame();
                } else {
                    // Update star backdrop and particles even when game has not started or is game over
                    stars.forEach(star => {
                        star.x -= star.speed * 0.2; // scroll slower on splash
                        if (star.x < 0) {
                            star.x = 480;
                            star.y = Math.random() * 560;
                        }
                    });
                    // particles still animate
                    for (let i = particles.length - 1; i >= 0; i--) {
                        const p = particles[i];
                        p.x += p.vx;
                        p.y += p.vy;
                        p.vy += 0.04;
                        p.life -= p.decay;
                        if (p.life <= 0) {
                            particles.splice(i, 1);
                        }
                    }
                }
                
                drawGame();
                animationFrameId = requestAnimationFrame(gameLoop);
            }

            function togglePause() {
                isPaused = !isPaused;
                const pauseBtn = document.getElementById('birdPauseBtn');
                if (pauseBtn) {
                    pauseBtn.innerHTML = isPaused ? '▶ Resume' : '⏸ Pause';
                }
            }

            // Bind click to the whole overlay (but filtering buttons/close action)
            const handleOverlayClick = (e) => {
                if (e.target === overlay) {
                    closeOverlay();
                    return;
                }

                if (e.target.closest('#birdCloseBtn') || e.target.closest('#birdSoundToggle') || e.target.closest('#birdPauseBtn')) {
                    return;
                }

                if (isGameOver) {
                    initGame();
                    hasStarted = true;
                    flapBird();
                } else if (!hasStarted) {
                    hasStarted = true;
                    flapBird();
                } else if (!isPaused) {
                    flapBird();
                }
            };

            overlay.addEventListener('click', handleOverlayClick);

            // Pause action button
            const pauseBtn = overlay.querySelector('#birdPauseBtn');
            pauseBtn.addEventListener('click', () => {
                if (hasStarted && !isGameOver) {
                    togglePause();
                }
            });

            // Sound Toggle
            const soundBtn = overlay.querySelector('#birdSoundToggle');
            soundBtn.addEventListener('click', () => {
                soundEnabled = !soundEnabled;
                localStorage.setItem('ab-bird-sound', soundEnabled);
                soundBtn.textContent = soundEnabled ? '🔊' : '🔇';
            });

            // Close button
            const closeBtn = overlay.querySelector('#birdCloseBtn');
            const closeOverlay = () => {
                isGameActive = false;
                if (animationFrameId) cancelAnimationFrame(animationFrameId);
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 400);
            };

            closeBtn.addEventListener('click', closeOverlay);

            // Start game
            initGame();
            animationFrameId = requestAnimationFrame(gameLoop);
        }
    }

    // ── Global Toast Notification System ──
    window.showToast = function(message, type = 'info', duration = 3000) {
        let container = document.getElementById('abToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'abToastContainer';
            container.className = 'ab-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `ab-toast ab-toast--${type}`;

        const icons = {
            success: '✅',
            error: '❌',
            info: 'ℹ️'
        };
        const icon = icons[type] || 'ℹ️';

        toast.innerHTML = `
            <span class="ab-toast-icon">${icon}</span>
            <span class="ab-toast-message">${message}</span>
            <button type="button" class="ab-toast-close">&times;</button>
        `;

        container.appendChild(toast);

        // Force reflow
        toast.offsetHeight;

        // Add show class
        toast.classList.add('show');

        // Setup auto-close
        const autoCloseTimeout = setTimeout(() => {
            closeToast();
        }, duration);

        function closeToast() {
            toast.classList.remove('show');
            toast.classList.add('hide');
            toast.addEventListener('transitionend', () => {
                toast.remove();
                if (container.children.length === 0) {
                    container.remove();
                }
            });
        }

        toast.querySelector('.ab-toast-close').addEventListener('click', () => {
            clearTimeout(autoCloseTimeout);
            closeToast();
        });
    };

    // ── 3D Card Tilt Hover Effect ──
    window.initTiltEffect = function() {
        const cards = document.querySelectorAll('.feature-card, .step-card, .buddy-card, .bab-btn');
        cards.forEach(card => {
            if (card.classList.contains('tilt-card')) return;
            card.classList.add('tilt-card');
            
            card.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                
                const rotateX = ((centerY - y) / centerY) * 10;
                const rotateY = ((x - centerX) / centerX) * 10;
                
                card.style.transform = `rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'rotateX(0deg) rotateY(0deg)';
            });
        });
    };

    // ── Homepage Testimonials Carousel ──
    function initTestimonialsCarousel() {
        const track = document.getElementById('testimonialTrack');
        const dotsContainer = document.getElementById('carouselDots');
        if (!track || !dotsContainer) return;

        const slides = track.querySelectorAll('.testimonial-card');
        const dots = dotsContainer.querySelectorAll('.dot');
        let currentIndex = 0;
        let slideInterval = null;

        function goToSlide(index) {
            currentIndex = index;
            track.style.transform = `translateX(-${currentIndex * 100}%)`;
            
            dots.forEach((dot, idx) => {
                dot.classList.toggle('active', idx === currentIndex);
            });
        }

        function nextSlide() {
            let nextIndex = currentIndex + 1;
            if (nextIndex >= slides.length) nextIndex = 0;
            goToSlide(nextIndex);
        }

        function startAutoSlide() {
            slideInterval = setInterval(nextSlide, 5000);
        }

        function stopAutoSlide() {
            clearInterval(slideInterval);
        }

        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                stopAutoSlide();
                goToSlide(index);
                startAutoSlide();
            });
        });

        // Initialize
        goToSlide(0);
        startAutoSlide();
    }

    function toggleThemeWithTransition(event) {
        const dark = !isDark();
        if (!document.startViewTransition) {
            setTheme(dark);
            return;
        }

        const x = event ? event.clientX : window.innerWidth / 2;
        const y = event ? event.clientY : window.innerHeight / 2;
        const endRadius = Math.hypot(
            Math.max(x, window.innerWidth - x),
            Math.max(y, window.innerHeight - y)
        );

        document.documentElement.classList.add('no-transition');

        const transition = document.startViewTransition(() => {
            setTheme(dark);
        });

        transition.ready.then(() => {
            document.documentElement.animate(
                {
                    clipPath: [
                        `circle(0px at ${x}px ${y}px)`,
                        `circle(${endRadius}px at ${x}px ${y}px)`
                    ]
                },
                {
                    duration: 650,
                    easing: 'cubic-bezier(0.4, 0, 0.2, 1)',
                    pseudoElement: '::view-transition-new(root)'
                }
            ).onfinish = () => {
                document.documentElement.classList.remove('no-transition');
            };
        });
    }

    // Initialize UI features on DOM content loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            renderHeaderAndFooter();
            const toggle = document.getElementById('themeToggle');
            if (toggle) {
                toggle.addEventListener('click', function (e) {
                    toggleThemeWithTransition(e);
                });
            }
            setTheme(isDark());
            spawnFloatingEmojis();
            setupBurgerDrawer();
            setupModals();
            setupBuddyBot();
            setupEasterEggs();
            window.initTiltEffect();
            initTestimonialsCarousel();
        });
    } else {
        renderHeaderAndFooter();
        const toggle = document.getElementById('themeToggle');
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                toggleThemeWithTransition(e);
            });
        }
        setTheme(isDark());
        spawnFloatingEmojis();
        setupBurgerDrawer();
        setupModals();
        setupBuddyBot();
        setupEasterEggs();
        window.initTiltEffect();
        initTestimonialsCarousel();
    }

})();
