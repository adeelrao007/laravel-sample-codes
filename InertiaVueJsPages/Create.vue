<template>
  <div>
    <Head :title="$trans('civic.create_new_category')" />
    <h1 class="mb-8 text-3xl font-bold">
      <Link class="text-sky-600 hover:text-sky-600" href="/civic-alerts/categories">{{ $trans('civic.manage_categories') }}</Link>
      <span class="font-medium text-sky-600">/</span>
      {{ $trans('civic.create_new_category') }}
    </h1>
    <div class="max-w-3xl overflow-hidden bg-white rounded-md shadow">
      <form @submit.prevent="store">
        <div class="flex flex-wrap p-8 -mb-8 -mr-6">
          <text-input v-model="form.name" :error="form.errors.name" label-color="text-gray-500" class="w-full pb-8 pr-6 lg:w-1/2" :label="$trans('civic.category_name')" />
        </div>
        <div class="flex items-center justify-end px-8 py-4 bg-white border-t border-gray-100">
          <loading-button :loading="form.processing" class="btn-black" type="submit">{{ $trans('civic.create_new_category') }}</loading-button>
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

export default {
  components: {
    Head,
    Link,
    LoadingButton,
    TextInput,
  },
  layout: Layout,
  remember: 'form',
  props: {},
  data() {
    return {
      form: this.$inertia.form({
        name: '',
      }),
    }
  },
  methods: {
    store() {
      this.form.post('/civic-alerts/categories')
    },
  },
}
</script>
