<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api';
import { extractErrorMessage } from '@/api/client';
import AppButton from '@/components/ui/AppButton.vue';

const route = useRoute();
const rating = ref(0);
const body = ref('');
const submitting = ref(false);
const submitted = ref(false);
const error = ref('');
const submitterName = ref('');
const selectedTags = ref([]);
const images = ref([]);
const fileInput = ref(null);

const TAG_OPTIONS = ['friendly_staff', 'short_wait', 'clean_facility', 'clear_explanation', 'painful_visit', 'long_wait', 'rushed'];

const previews = computed(() => images.value.map((f) => URL.createObjectURL(f)));

function toggleTag(tag) {
    const i = selectedTags.value.indexOf(tag);
    if (i >= 0) selectedTags.value.splice(i, 1);
    else selectedTags.value.push(tag);
}

function onFilesPicked(event) {
    const picked = Array.from(event.target.files || []);
    const merged = [...images.value, ...picked].slice(0, 5);
    images.value = merged;
    if (fileInput.value) fileInput.value.value = '';
}

function removeImage(i) {
    images.value.splice(i, 1);
}

async function submit() {
    if (rating.value < 1) {
        error.value = 'Please choose a rating.';
        return;
    }
    submitting.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('rating', String(rating.value));
        if (body.value) fd.append('body', body.value);
        if (submitterName.value) fd.append('submitted_by_name', submitterName.value);
        selectedTags.value.forEach((t) => fd.append('tags[]', t));
        images.value.forEach((file) => fd.append('images[]', file));
        await api.reviewSubmit(route.params.visitId, fd);
        submitted.value = true;
    } catch (e) {
        error.value = extractErrorMessage(e, 'Could not submit review.');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-sky-50 to-indigo-100 flex items-center justify-center p-6">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full p-8">
            <div v-if="submitted" class="text-center py-8" data-test="submitted-banner">
                <div class="text-6xl mb-4">✓</div>
                <h2 class="text-2xl font-bold mb-2">Thank you!</h2>
                <p class="text-gray-600">Your feedback helps us take better care of every patient.</p>
            </div>
            <div v-else>
                <h1 class="text-2xl font-bold mb-1">How was your visit?</h1>
                <p class="text-sm text-gray-600 mb-6">Tap a star to rate, then tell us more.</p>

                <div class="flex gap-2 mb-4" role="radiogroup" aria-label="Rating">
                    <button
                        v-for="n in 5"
                        :key="n"
                        type="button"
                        class="text-4xl transition"
                        :class="rating >= n ? 'text-yellow-400' : 'text-gray-300'"
                        :data-test="`star-${n}`"
                        @click="rating = n"
                        :aria-pressed="rating >= n"
                    >★</button>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Your name (optional)</label>
                    <input v-model="submitterName" type="text" class="block w-full border rounded px-3 py-2 text-sm" data-test="submitter-name" />
                </div>

                <div class="mb-4">
                    <div class="text-sm font-medium mb-2">Tags</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="tag in TAG_OPTIONS"
                            :key="tag"
                            type="button"
                            :class="[
                                'px-3 py-1 rounded-full text-xs border',
                                selectedTags.includes(tag) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700'
                            ]"
                            :data-test="`tag-${tag}`"
                            @click="toggleTag(tag)"
                        >{{ tag.replace(/_/g, ' ') }}</button>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Comments</label>
                    <textarea v-model="body" rows="4" class="block w-full border rounded px-3 py-2 text-sm" data-test="review-body" />
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Photos (optional, up to 5)</label>
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/*"
                        multiple
                        class="block w-full text-sm"
                        data-test="review-images"
                        :disabled="images.length >= 5"
                        @change="onFilesPicked"
                    />
                    <div v-if="previews.length" class="flex gap-2 mt-2 flex-wrap">
                        <div v-for="(src, i) in previews" :key="i" class="relative">
                            <img :src="src" class="h-16 w-16 object-cover rounded border" alt="" />
                            <button
                                type="button"
                                class="absolute -top-1 -right-1 bg-red-600 text-white rounded-full w-5 h-5 text-xs leading-5"
                                :data-test="`remove-image-${i}`"
                                @click="removeImage(i)"
                            >×</button>
                        </div>
                    </div>
                </div>

                <div v-if="error" class="text-sm text-red-600 mb-3" role="alert">{{ error }}</div>

                <AppButton :loading="submitting" class="w-full" data-test="submit-review" @click="submit">Submit review</AppButton>
            </div>
        </div>
    </div>
</template>
