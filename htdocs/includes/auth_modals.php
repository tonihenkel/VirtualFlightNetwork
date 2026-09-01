<?php
require_once __DIR__ . '/csrf.php';

if (!isset($currentLanguage)) {
    $currentLanguage = 'en';
}

$countries =
    require 'includes/countries.php';

    $divisions = [];

try
{
    require_once 'execute/config.php';

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass
    );

    $stmt = $pdo->query(
        "SELECT
            code,
            name,
            join_enabled
         FROM divisions
         WHERE is_active = 1
         ORDER BY name"
    );

    $divisions =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}
catch (Exception $e)
{
}

$firstOpenDivision = null;
foreach ($divisions as $divisionOption) {
    if ((int)($divisionOption['join_enabled'] ?? 1) === 1) {
        $firstOpenDivision = $divisionOption;
        break;
    }
}

?>

<!-- LOGIN MODAL -->

<div class="modal-overlay"
     id="loginModal">

    <div class="modal-box">

        <button class="modal-close"
                onclick="closeModal('loginModal')">
            X
        </button>

        <h2>
            <?php echo htmlspecialchars(t('login_title')); ?>
        </h2>

        <form method="POST"
              action="web_login.php">

            <input type="hidden" name="csrf"
                   value="<?php echo htmlspecialchars(csrfToken('login')); ?>">

            <input type="text"
                   name="username"
                   placeholder="<?php echo htmlspecialchars(t('login_username')); ?>"
                   required>

            <input type="password"
                   name="password"
                   placeholder="<?php echo htmlspecialchars(t('login_password')); ?>"
                   required>

            <input type="hidden"
                   name="return_to"
                   value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">

            <button type="submit"
                    class="modal-button">

                <?php echo htmlspecialchars(t('login_button')); ?>

            </button>

        </form>

        <p style="margin:14px 0 0;text-align:center;">
            <a href="forgot_password.php?lang=<?php echo urlencode($currentLanguage); ?>"
               style="color:#49adff;text-decoration:none;">
                <?php echo htmlspecialchars(t('auth_forgot_password')); ?>
            </a>
        </p>
        <p style="margin:8px 0 0;text-align:center;">
            <a href="ban_appeal.php?lang=<?php echo urlencode($currentLanguage); ?>"
               style="color:#49adff;text-decoration:none;">
                <?php echo htmlspecialchars(t('auth_ban_appeal')); ?>
            </a>
        </p>

    </div>

</div>

<?php if (empty($maintenanceMode) && !empty($registrationEnabled)): ?>
<!-- REGISTER MODAL -->

<div class="modal-overlay"
     id="registerModal">

    <div class="modal-box">

        <button class="modal-close"
                onclick="closeModal('registerModal')">
            X
        </button>

        <h2>
            <?php echo htmlspecialchars(t('register_title')); ?>
        </h2>

        <form method="POST" id="registerForm"
              action="web_register.php">

            <input type="hidden" name="csrf"
                   value="<?php echo htmlspecialchars(csrfToken('register')); ?>">

            <input type="text"
                   name="username"
                   placeholder="<?php echo htmlspecialchars(t('register_username')); ?>"
                   required>

            <input type="email"
                   name="email"
                   placeholder="<?php echo htmlspecialchars(t('register_email')); ?>"
                   required>

            <input type="text"
                   name="real_name"
                   placeholder="<?php echo htmlspecialchars(t('register_realname')); ?>"
                   required>

            <div class="airport-autocomplete">
                <input type="text"
                       id="registerHomeAirport"
                       maxlength="60"
                       autocomplete="off"
                       placeholder="<?php echo htmlspecialchars(t('register_home_airport')); ?>"
                       title="<?php echo htmlspecialchars(t('register_home_airport_help')); ?>"
                       required>
                <input type="hidden" id="registerHomeAirportCode" name="home_airport_icao">
                <div id="registerHomeAirportResults" class="airport-autocomplete-results" hidden></div>
            </div>



            <div class="vfn-dropdown" id="countryDropdown">

                <input type="hidden"
                       name="country_code"
                       id="country_code"
                       required>

                <div class="vfn-dropdown-selected">

                    <?php echo htmlspecialchars(t('register_country')); ?>

                </div>

                <div class="vfn-dropdown-list">

                    <?php foreach ($countries as $countryCode => $countryName): ?>

                        <div
                            class="vfn-dropdown-item"
                            data-value="<?php echo htmlspecialchars($countryCode); ?>">

                            <img
                                src="images/flags/<?php echo strtolower($countryCode); ?>.png"
                                class="country-flag"
                                alt="">

                            <?php echo htmlspecialchars($countryName); ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>




            <div class="vfn-dropdown" id="divisionDropdown">

                <input type="hidden"
                    name="division_code"
                    id="division_code"
                    value="<?php echo htmlspecialchars($firstOpenDivision['code'] ?? ''); ?>"
                    required>

                <div class="vfn-dropdown-selected">

                    <img
                        src="images/flags/<?php echo strtolower($firstOpenDivision['code'] ?? ''); ?>.png"
                        class="country-flag"
                        alt=""> <?php
                    echo htmlspecialchars(
                        $firstOpenDivision['name']
                        ?? 'Select Division'
                    );
                    ?>

                </div>

                <div class="vfn-dropdown-list">

                    <?php foreach ($divisions as $division): ?>

                        <div
                            class="vfn-dropdown-item<?php echo (int)($division['join_enabled'] ?? 1) === 1 ? '' : ' disabled'; ?>"
                            data-disabled="<?php echo (int)($division['join_enabled'] ?? 1) === 1 ? '0' : '1'; ?>"
                            data-value="<?php echo htmlspecialchars($division['code']); ?>">

                            <img
                                src="images/flags/<?php echo strtolower($division['code']); ?>.png"
                                class="country-flag"
                                alt=""> <?php echo htmlspecialchars($division['name']); ?><?php if ((int)($division['join_enabled'] ?? 1) !== 1): ?> (<?php echo htmlspecialchars(t('division_closed')); ?>)<?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>





            <input type="password"
                   name="password"
                   placeholder="<?php echo htmlspecialchars(t('register_password')); ?>"
                   required>

            <input type="password"
                   name="password_repeat"
                   placeholder="<?php echo htmlspecialchars(t('register_password_repeat')); ?>"
                   required>

            <button type="submit"
                    class="modal-button">

                <?php echo htmlspecialchars(t('register_button')); ?>

            </button>

        </form>

    </div>

</div>
<?php endif; ?>

<style>

.modal-overlay {
    position: fixed;

    inset: 0;

    background:
        rgba(0,0,0,0.72);

    display: none;

    align-items: center;
    justify-content: center;

    z-index: 999999;
}

.modal-overlay.active,
.modal-overlay.open {
    display: flex;
}

.modal-box {
    width: 100%;
    max-width: 500px;

    background:
        rgba(15,20,30,0.96);

    border:
        1px solid rgba(255,255,255,0.12);

    border-radius: 18px;

    padding: 32px;

    position: relative;

    box-shadow:
        0 25px 80px rgba(0,0,0,0.55);
}

.modal-box h2 {
    margin-top: 0;
    margin-bottom: 24px;

    color: white;

    font-size: 28px;
}

.modal-box form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.modal-box input,
.modal-box select {
    width: 100%;

    padding: 14px 16px;

    border-radius: 10px;

    border:
        1px solid rgba(255,255,255,0.14);

    background:
        rgba(255,255,255,0.08);

    color: white;

    font-size: 15px;

    outline: none;

    box-sizing: border-box;
}

.modal-box select {
    cursor: pointer;

    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background-image:
        linear-gradient(
            45deg,
            transparent 50%,
            rgba(255,255,255,0.7) 50%
        ),
        linear-gradient(
            135deg,
            rgba(255,255,255,0.7) 50%,
            transparent 50%
        );

    background-position:
        calc(100% - 20px) calc(50% - 3px),
        calc(100% - 14px) calc(50% - 3px);

    background-size:
        6px 6px,
        6px 6px;

    background-repeat: no-repeat;
}

.modal-box select option {
    background: #151a24;
    color: white;
}

.modal-box input::placeholder {
    color:
        rgba(255,255,255,0.55);
}

.modal-box input:focus,
.modal-box select:focus {
    border-color:
        rgba(0,255,204,0.65);
}

.modal-button {
    margin-top: 8px;

    padding: 14px;

    border: 0;

    border-radius: 10px;

    background:
        linear-gradient(
            135deg,
            #00bfff,
            #00ffcc
        );

    color: #031018;

    font-size: 15px;
    font-weight: bold;

    cursor: pointer;

    transition:
        transform 0.15s ease,
        opacity 0.15s ease;
}

.modal-button:hover {
    transform: translateY(-1px);

    opacity: 0.95;
}

.modal-close {
    position: absolute;

    top: 14px;
    right: 16px;

    background: transparent;
    border: 0;

    color:
        rgba(255,255,255,0.7);

    font-size: 28px;

    cursor: pointer;
}

.modal-close:hover {
    color: white;
}

@media (max-width: 600px) {

    .modal-box {
        margin: 18px;

        padding: 26px;
    }
}

.vfn-dropdown {
    position: relative;
    width: 100%;
    box-sizing: border-box;
}

.vfn-dropdown-selected {

    width: 100%;
    box-sizing: border-box;

    padding: 14px 16px;

    border-radius: 10px;

    border:
        1px solid rgba(255,255,255,0.14);

    background:
        rgba(255,255,255,0.08);

    color: white;

    cursor: pointer;

    user-select: none;
}

.vfn-dropdown-list {

    display: none;

    position: absolute;

    left: 0;
    right: 0;

    top: calc(100% + 4px);

    max-height: 250px;
    box-sizing: border-box;

    overflow-y: auto;

    background:
        rgba(15,20,30,0.98);

    border:
        1px solid rgba(255,255,255,0.12);

    border-radius: 10px;

    z-index: 999999;
}

.vfn-dropdown.open .vfn-dropdown-list {
    display: block;
}

.vfn-dropdown-item {

    padding: 12px 16px;

    color: white;

    cursor: pointer;
}

.vfn-dropdown-item:hover {

    background:
        rgba(255,255,255,0.08);
}

.vfn-dropdown-item {
    display: flex;
    align-items: center;
    gap: 10px;

    padding: 12px 16px;

    color: white;

    cursor: pointer;
}

.vfn-dropdown-item.disabled {
    opacity: .48;
    cursor: not-allowed;
}

.vfn-dropdown-item.disabled:hover {
    background: transparent;
}

.country-flag {
    width: 20px;
    height: 14px;

    object-fit: cover;

    border-radius: 2px;

    flex-shrink: 0;
}

.airport-autocomplete { position: relative; width: 100%; }
.airport-autocomplete-results {
    position: absolute; left: 0; right: 0; top: calc(100% + 4px);
    z-index: 1000000; max-height: 250px; overflow-y: auto;
    border: 1px solid rgba(255,255,255,.16); border-radius: 10px;
    background: rgba(15,20,30,.99); box-shadow: 0 14px 35px rgba(0,0,0,.45);
}
.airport-autocomplete-result {
    display: block; width: 100%; padding: 11px 14px; border: 0;
    border-bottom: 1px solid rgba(255,255,255,.08); background: transparent;
    color: #fff; text-align: left; cursor: pointer;
}
.airport-autocomplete-result:hover { background: rgba(255,255,255,.09); }
.airport-autocomplete-result strong { color: #58e0ff; }
.airport-autocomplete-result small { display: block; margin-top: 3px; color: rgba(255,255,255,.62); }

</style>

<script>

function openModal(id)
{
    const modal =
        document.getElementById(id);

    if (modal) {

        modal.classList.add('open');
        modal.classList.add('active');

    }
}

function closeModal(id)
{
    const modal =
        document.getElementById(id);

    if (modal) {

        modal.classList.remove('open');
        modal.classList.remove('active');

    }
}

document.addEventListener(
    'keydown',
    function(event)
    {
        if (event.key === 'Escape') {

            document
                .querySelectorAll('.modal-overlay')
                .forEach(
                    function(modal)
                    {
                        modal.classList.remove('open');
                        modal.classList.remove('active');
                    }
                );
        }
    }
);

document
    .querySelectorAll('.modal-overlay')
    .forEach(
        function(modal)
        {
            modal.addEventListener(
                'click',
                function(event)
                {
                    if (event.target === modal) {

                        modal.classList.remove('open');
                        modal.classList.remove('active');

                    }
                }
            );
        }
    );



    document
        .querySelectorAll('.vfn-dropdown')
        .forEach(function(dropdown)
        {
            const selected =
                dropdown.querySelector('.vfn-dropdown-selected');

            const hiddenInput =
                dropdown.querySelector('input[type="hidden"]');

            if (!selected || !hiddenInput) {
                return;
            }

            selected.addEventListener(
                'click',
                function(event)
                {
                    event.stopPropagation();

                    document
                        .querySelectorAll('.vfn-dropdown')
                        .forEach(function(otherDropdown)
                        {
                            if (otherDropdown !== dropdown) {
                                otherDropdown.classList.remove('open');
                            }
                        });

                    dropdown.classList.toggle('open');
                }
            );

            dropdown
                .querySelectorAll('.vfn-dropdown-item')
                .forEach(function(item)
                {
                    item.addEventListener(
                        'click',
                        function(event)
                        {
                            event.stopPropagation();

                            if (item.dataset.disabled === '1') {
                                return;
                            }

                            selected.innerHTML =
                                item.innerHTML;

                            hiddenInput.value =
                                item.dataset.value;

                            dropdown.classList.remove('open');
                        }
                    );
                });
        });

    document.addEventListener(
        'click',
        function()
        {
            document
                .querySelectorAll('.vfn-dropdown')
                .forEach(function(dropdown)
                {
                    dropdown.classList.remove('open');
                });
        }
    );

    (function initRegisterAirportSearch() {
        const input = document.getElementById('registerHomeAirport');
        const codeInput = document.getElementById('registerHomeAirportCode');
        const results = document.getElementById('registerHomeAirportResults');
        const form = document.getElementById('registerForm');
        if (!input || !codeInput || !results || !form) return;
        let timer = null;
        let controller = null;
        const escapeText = value => String(value || '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
        const closeResults = () => { results.hidden = true; results.innerHTML = ''; };

        input.addEventListener('input', function() {
            const query = input.value.trim();
            codeInput.value = query.toUpperCase() === 'ZZZZ' ? 'ZZZZ' : '';
            input.setCustomValidity('');
            clearTimeout(timer);
            if (controller) controller.abort();
            if (query.toUpperCase() === 'ZZZZ' || query.length < 2) {
                closeResults();
                return;
            }
            timer = setTimeout(async function() {
                controller = new AbortController();
                try {
                    const response = await fetch('execute/airport_lookup.php?q=' + encodeURIComponent(query), {signal: controller.signal});
                    const data = await response.json();
                    const airports = (Array.isArray(data.airports) ? data.airports : [])
                        .filter(airport => /^[A-Z0-9][A-Z0-9-]{1,13}$/.test(String(airport.code || '').toUpperCase()));
                    results.innerHTML = airports.map(airport =>
                        '<button type="button" class="airport-autocomplete-result" data-code="' + escapeText(airport.code) + '" data-name="' + escapeText(airport.name) + '">' +
                        '<strong>' + escapeText(airport.code) + '</strong> · ' + escapeText(airport.name) +
                        '<small>' + escapeText(airport.municipality || '') + '</small></button>'
                    ).join('');
                    results.hidden = airports.length === 0;
                } catch (error) {
                    if (error.name !== 'AbortError') closeResults();
                }
            }, 250);
        });
        results.addEventListener('mousedown', function(event) {
            const option = event.target.closest('[data-code]');
            if (!option) return;
            event.preventDefault();
            input.value = option.dataset.code;
            codeInput.value = option.dataset.code;
            input.setCustomValidity('');
            closeResults();
        });
        form.addEventListener('submit', function(event) {
            if (codeInput.value) return;
            event.preventDefault();
            input.setCustomValidity(<?php echo json_encode(t('register_home_airport_invalid')); ?>);
            input.reportValidity();
        });
        input.addEventListener('blur', () => setTimeout(closeResults, 120));
    })();

</script>
