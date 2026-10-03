<script>
    document.addEventListener("DOMContentLoaded", function() {

        <?php if (isset($_SESSION['session_expired'])): ?>
            Swal.fire({
                icon: 'warning',
                title: 'Session Expired',
                text: <?php echo json_encode($_SESSION['session_expired']); ?>,
                confirmButtonText: 'OK'
            });
            <?php unset($_SESSION['session_expired']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: <?php echo json_encode($_SESSION['login_error']); ?>,
                confirmButtonText: 'OK'
            });
            <?php unset($_SESSION['login_error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Login Successful',
                text: <?php echo json_encode($_SESSION['login_success']); ?>,
                confirmButtonText: 'OK'
            });
            <?php unset($_SESSION['login_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['logout_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Logged Out',
                text: <?php echo json_encode($_SESSION['logout_success']); ?>,
                confirmButtonText: 'OK'
            });
            <?php unset($_SESSION['logout_success']); ?>
        <?php endif; ?>
    });
</script>


</body>

</html>