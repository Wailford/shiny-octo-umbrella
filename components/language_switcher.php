<!-- Language Switcher -->
<div style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
    <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display: inline;">
        <input type="hidden" name="change_language" value="1">
        <select name="language" onchange="this.form.submit()" class="form-control" style="width: auto; display: inline-block; padding: 5px 10px; border-radius: 5px; border: 1px solid #ddd;">
            <option value="en" <?php echo getCurrentLanguage() == 'en' ? 'selected' : ''; ?>>English</option>
            <option value="tw" <?php echo getCurrentLanguage() == 'tw' ? 'selected' : ''; ?>>Twi (Akan)</option>
        </select>
    </form>
</div>

<?php
// Handle language change
if (isset($_POST['change_language']) && isset($_POST['language'])) {
    setLanguage($_POST['language']);
    header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['dev']) ? '?dev=1' : ''));
    exit;
}
?>
