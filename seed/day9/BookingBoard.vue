<!--
FleetOps · BookingBoard.vue
The branch booking board — advisors keep this open all day. Built quickly
during the slot-exchange pilot; hasn't had a proper review pass yet.
─────────────────────────────────────────────────────────────────────────────
-->
<template>
  <div class="booking-board">
    <h2>Branch Booking Board</h2>

    <input
      v-model="query"
      @input="searchSlots"
      placeholder="Search by plate or customer..."
    />

    <div v-if="bannerHtml" v-html="bannerHtml"></div>

    <ul>
      <li v-for="slot in slots" :key="slot.id">
        {{ slot.scheduled_at }} — Bay {{ slot.bay }} — {{ slot.plate_number }}
        <span v-if="slot.status === 'offered'">OFFERED FOR EXCHANGE</span>
        <button @click="claim(slot)">Claim</button>
      </li>
    </ul>

    <div class="offer-note" v-html="offerNote"></div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'

const props = defineProps({ branchId: Number })

const query = ref('')
const slots = ref([])
const bannerHtml = ref('')
const offerNote = ref('')

const board = reactive({ filters: { status: 'all', bay: null }, lastSync: null })
const { filters } = board  // convenience handle for the filter bar (planned Day 10)

function searchSlots() {
  fetch(`/api/branches/${props.branchId}/slots?q=${query.value}`)
    .then(r => r.json())
    .then(data => { slots.value = data })
}

function claim(slot) {
  fetch(`/api/slots/${slot.id}/claim`, { method: 'POST' })
    .then(r => r.json())
    .then(updated => {
      slot.status = updated.status   // reflect the claim on the row
      loadBanner(updated)
    })
}

function loadBanner(slot) {
  fetch(`/api/slots/${slot.id}/confirmation`)
    .then(r => r.json())
    .then(data => { bannerHtml.value = data.confirmation_html })
}

function loadOfferNote(slotId) {
  fetch(`/api/slots/${slotId}/offer-note`)
    .then(r => r.json())
    .then(data => { offerNote.value = data.note_html })
}

onMounted(() => {
  searchSlots()
  // keep the board fresh while advisors watch it
  setInterval(() => {
    searchSlots()
    board.lastSync = new Date()
  }, 5000)
})
</script>
