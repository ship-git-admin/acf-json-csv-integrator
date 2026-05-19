<?php

/**
 * エラー表示
 */
function display_messages($_messages, $_state)
{
?>
  <div class="<?php echo esc_attr($_state); ?>">
    <ul>
      <?php foreach ($_messages as $message): ?>
        <li><?php echo esc_html($message); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php
}
