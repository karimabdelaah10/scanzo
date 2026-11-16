<script setup>
import { computed } from 'vue'
import { session } from '../../shared/session.js'

/**
 * What the owner must know before touching anything, on every screen.
 *
 * Two different things, kept apart on purpose:
 *  - Suspended: the shop is read only. Everyone in the dashboard sees it,
 *    because everyone's buttons are about to stop working.
 *  - Subscription ended or ending: the owner only. It never stops anything, so
 *    a manager has nothing to do about it.
 *
 * Suspended wins when both apply. The data comes from the one /api/me call at
 * start-up; nothing here waits for a request to fail.
 */
const banner = computed(() => {
    if (session.readOnly) {
        return {
            tone: 'bad',
            text: 'الاشتراك متوقف. بياناتك متاحة للقراءة بس، ومفيش حاجة تتعدّل لحد ما يتفعّل تاني.',
        }
    }

    if (session.user?.role !== 'owner') return null

    const sub = session.subscription

    if (sub?.state === 'expired') {
        return { tone: 'bad', text: 'الاشتراك انتهى. البيع لسه شغّال، بس جدّد الاشتراك في أقرب وقت.' }
    }

    if (sub?.state === 'expiring') {
        const when = sub.days_left === 0
            ? 'النهارده'
            : sub.days_left === 1 ? 'بكرة' : `بعد ${sub.days_left} يوم`

        return { tone: 'warn', text: `الاشتراك بينتهي ${when}. جدّده عشان ميقفش عندك.` }
    }

    return null
})
</script>

<template>
  <p v-if="banner" class="msg status-banner" :class="banner.tone" role="status">{{ banner.text }}</p>
</template>
