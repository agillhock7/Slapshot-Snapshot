<script setup>
import { computed, ref } from "vue";
import { mediaItems } from "./media";

const filter = ref("all");
const selected = ref(null);

const media = computed(() => {
  if (filter.value === "all") return mediaItems;
  return mediaItems.filter((item) => item.type === filter.value);
});

const titleCase = (value) => value.charAt(0).toUpperCase() + value.slice(1);
const openItem = (item) => (selected.value = item);
const closeItem = () => (selected.value = null);
</script>

<template>
  <div class="page">
    <header class="hero">
      <p class="eyebrow">Slapshot Snapshot</p>
      <h1>Game Day Memories</h1>
      <p class="subtitle">
        A private family hub to celebrate goals, growth, and every shift.
      </p>
    </header>

    <section class="controls">
      <button
        v-for="type in ['all', 'photo', 'video']"
        :key="type"
        :class="['chip', { active: filter === type }]"
        @click="filter = type"
      >
        {{ titleCase(type) }}
      </button>
    </section>

    <main class="grid">
      <article
        v-for="item in media"
        :key="item.id"
        class="card"
        @click="openItem(item)"
      >
        <img :src="item.thumb" :alt="item.title" />
        <div class="card-body">
          <p class="meta">{{ item.date }} • {{ item.opponent }}</p>
          <h2>{{ item.title }}</h2>
          <p>{{ item.caption }}</p>
        </div>
      </article>
    </main>

    <div v-if="selected" class="modal-backdrop" @click.self="closeItem">
      <section class="modal">
        <button class="close" @click="closeItem">Close</button>
        <h3>{{ selected.title }}</h3>
        <p class="meta">{{ selected.date }} • {{ selected.opponent }}</p>
        <p>{{ selected.caption }}</p>

        <img
          v-if="selected.type === 'photo'"
          :src="selected.src"
          :alt="selected.title"
          class="modal-image"
        />
        <iframe
          v-else
          class="video"
          :src="selected.src"
          title="Video highlight"
          frameborder="0"
          allowfullscreen
        />
      </section>
    </div>
  </div>
</template>
