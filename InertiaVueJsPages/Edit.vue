<template>
  <div>
    <Head :title="`${form.name}`" />
    <div class="flex justify-start max-w-3xl mb-8">
      <h1 class="text-3xl font-bold">
        <Link class="text-sky-600 hover:text-sky-600" href="/civic-alerts/categories">{{ $trans('civic.manage_categories') }}</Link>
        <span class="font-medium text-sky-600">/</span>
        {{ form.name }}
      </h1>
    </div>
    <trashed-message v-if="civicAlertCategory.deleted_at" class="mb-6" @restore="restore"> {{ $trans('civic.this_category_has_been_deleted') }} </trashed-message>
    <div class="max-w-3xl overflow-hidden bg-white rounded-md shadow">
      <form @submit.prevent="update">
        <div class="flex flex-wrap p-8 -mb-8 -mr-6">
          <text-input v-model="form.name" :error="form.errors.name" label-color="text-gray-500" class="w-full pb-8 pr-6 lg:w-1/2" :label="$trans('civic.category_name')" />
        </div>
        <div class="flex items-center px-8 py-4 border-t border-gray-100 bg-gray-50">
          <button v-if="!civicAlertCategory.deleted_at" class="text-red-600 hover:underline" tabindex="-1" type="button" @click="destroy">{{ $trans('civic.delete_category') }}</button>
          <loading-button :loading="form.processing" class="ml-auto btn-black" type="submit">{{ $trans('common.update') }}</loading-button>
        </div>
      </form>
    </div>
  </div>
</template>

<script>
import { Head, Link } from '@inertiajs/inertia-vue3'
import Layout from '@/Shared/Layout'
import TextInput from '@/Shared/TextInput'
import LoadingButton from '@/Shared/LoadingButton'
import TrashedMessage from '@/Shared/TrashedMessage'

export default {
  components: {
    Head,
    Link,
    LoadingButton,
    TextInput,
    TrashedMessage,
  },
  layout: Layout,
  props: {
    civicAlertCategory: Object,
  },
  remember: 'form',
  data() {
    return {
      form: this.$inertia.form({
        _method: 'put',
        name: this.civicAlertCategory.name,
      }),
    }
  },
  methods: {
    update() {
      this.form.post(`/civic-alerts/categories/${this.civicAlertCategory.id}`, {
        onSuccess: () => '',
      })
    },
    destroy() {
      if (confirm(this.$trans('civic.are_you_sure_you_want_to_delete_this_category'))) {
        this.$inertia.delete(`/civic-alerts/categories/${this.civicAlertCategory.id}`)
      }
    },
    restore() {
      if (confirm(this.$trans('civic.are_you_sure_you_want_to_restore_this_category'))) {
        this.$inertia.put(`/civic-alerts/categories/${this.civicAlertCategory.id}/restore`)
      }
    },
  },
}
</script>
