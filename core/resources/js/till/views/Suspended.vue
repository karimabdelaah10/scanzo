<script setup>
/**
 * Shown instead of the sell screen when the shop is suspended, so a seller
 * learns it on opening the till rather than after building a cart that fails
 * at the confirm button. The server refuses the sale regardless; this only
 * saves the wasted work.
 *
 * Signing out, and the way to the dashboard for those who have one, come from
 * the strip App.vue already draws under the sell view.
 */
</script>

<template>
  <div class="screen">
    <div class="center">
      <h2>البيع متوقف</h2>
      <p class="msg bad" style="margin-top: 12px">
        اشتراك المحل متوقف دلوقتي، فمش هينفع تعمل فاتورة أو مرتجع.
        كلّم صاحب المحل.
      </p>
    </div>
  </div>
</template>
