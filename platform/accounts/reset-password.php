<?php
require_once("../init.php");
include("acc_header.php");

$token = $_GET["token"];

$token_hash = hash("sha256", $token);

require __DIR__ . "/../config.php";

$sql = "SELECT * FROM users
        WHERE reset_token_hash = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param("s", $token_hash);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

if ($user === null) {
    echo "<div class='login-card text-center'>
            <h3 class='login-title'>Invalid or Expired Link</h3>
            <p>Please request a new password reset <a href='forgot_password.php'>here</a>.</p>
          </div>";
    include("acc_footer.php");
    exit;
}

if (strtotime($user["reset_token_expires_at"]) <= time()) {
    echo "<div class='login-card text-center'>
            <h3 class='login-title'>Invalid or Expired Link</h3>
            <p>Please request a new password reset <a href='forgot_password.php'>here</a>.</p>
          </div>";
    include("acc_footer.php");
    exit;
}

?>

<style>

body{
    margin:0;
    min-height:100vh;
    font-family:'Poppins',sans-serif;
    background:url('../assets/SariSmart_2nd_bg.png') center center/cover no-repeat;
    display:flex;
    justify-content:center;
    align-items:center;
}

.reset-wrapper{
    position:relative;
    width:100%;
    display:flex;
    justify-content:center;
    align-items:center;
    z-index:2;
}

.reset-card{
    width:420px;
    background:rgba(255,255,255,.55);
    backdrop-filter:blur(8px);
    border:1px solid #555;
    border-radius:28px;
    padding:35px;
}

.logo{
    width:95px;
}

.title{
    font-weight:700;
    font-size:34px;
    color:#0A2A63;
}

.subtitle{
    color:#777;
    font-size:14px;
    margin-top:10px;
    margin-bottom:20px;
}

.form-label{
    font-weight:600;
    font-size:14px;
    color:#0A2A63;
}

.password-box{
    height:52px;
    border:1px solid #bdbdbd;
    border-radius:30px;
    overflow:hidden;
    background:#fff;
}

.password-box .input-group-text{
    border:none;
    background:transparent;
}

.password-box .btn{
    border:none;
}

.form-control{
    border:none;
    box-shadow:none;
}

.form-control:focus{
    box-shadow:none;
}

/* Button */

.btn-reset{
    height:52px;
    border-radius:30px;
    background:#032B63;
    color:#fff;
    font-weight:600;
    border:none;
}

.btn-reset:hover{
    background:#FDB515;
    color:#032B63;
}

.login-link{
    margin-top:18px;
    text-align:center;
    font-size:14px;
}

.login-link a{
    font-weight:600;
    text-decoration:none;
}

</style>

<div class="reset-wrapper">
    <div class="reset-card">
        <div class="text-center mb-4">
            <img src="../assets/logo.png" class="logo mb-3">
            <h2 class="title">Reset Your Password</h2>
            <p class="subtitle">
                Please enter your new password
                <br>
                to secure your account.
            </p>
        </div>
        <form method="POST" action="process-reset-password.php">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>
            <div class="mb-3">
                <label class="form-label">
                    New Password
                </label>
                <div class="input-group password-box">
                    <span class="input-group-text">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input
                        type="password"
                        class="form-control"
                        placeholder="Enter new password"
                        id="password">
                    <button
                        class="btn"
                        type="button"
                        onclick="togglePassword('password',this)">
                        <i class="fa-solid fa-eye-slash"></i>
                    </button>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">
                    Confirm New Password
                </label>
                <div class="input-group password-box">
                    <span class="input-group-text">
                        <i class="fa-solid fa-lock"></i>
                    </span>
                    <input
                        type="password"
                        class="form-control"
                        placeholder="Confirm new password"
                        id="confirm">
                    <button
                        class="btn"
                        type="button"
                        onclick="togglePassword('confirm',this)">
                        <i class="fa-solid fa-eye-slash"></i>
                    </button>
                </div>
            </div>
            <button class="btn btn-reset w-100">
                <i class="fa-solid fa-lock me-2"></i>
                Reset Password
            </button>
        </form>
        <div class="login-link">
            Remember your password?
            <a href="/accounts/acc_log_in.php">
                Login
            </a>
        </div>
    </div>
</div>

<script>

function togglePassword(id,btn){
    let input=document.getElementById(id);
    let icon=btn.querySelector("i");
    if(input.type==="password"){
    input.type="text";
    icon.classList.replace("fa-eye-slash","fa-eye");
    }else{
    input.type="password";
    icon.classList.replace("fa-eye","fa-eye-slash");
    }
}

</script>

<?php include("acc_footer.php"); ?>