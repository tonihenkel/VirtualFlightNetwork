<?php

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
            name
         FROM divisions
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

    </div>

</div>

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

        <form method="POST"
              action="web_register.php">

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
                    value="<?php echo htmlspecialchars($divisions[0]['code'] ?? ''); ?>"
                    required>

                <div class="vfn-dropdown-selected">

                    <img
                        src="images/flags/<?php echo strtolower($divisions[0]['code']); ?>.png"
                        class="country-flag"
                        alt=""> <?php
                    echo htmlspecialchars(
                        $divisions[0]['name']
                        ?? 'Select Division'
                    );
                    ?>

                </div>

                <div class="vfn-dropdown-list">

                    <?php foreach ($divisions as $division): ?>

                        <div
                            class="vfn-dropdown-item"
                            data-value="<?php echo htmlspecialchars($division['code']); ?>">

                            <img
                                src="images/flags/<?php echo strtolower($division['code']); ?>.png"
                                class="country-flag"
                                alt=""> <?php echo htmlspecialchars($division['name']); ?>

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
    max-width: 430px;

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
}

.vfn-dropdown-selected {

    width: 100%;

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

.country-flag {
    width: 20px;
    height: 14px;

    object-fit: cover;

    border-radius: 2px;

    flex-shrink: 0;
}

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

</script>