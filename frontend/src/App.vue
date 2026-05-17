<template>
  <RouterView />
  <ToastContainer />
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router'
import ToastContainer from './components/ToastContainer.vue'
</script>

<style>
/* Reset and global styles are in assets/main.css */
</style>
