<template>
  <div>
    <div class="p-3 mt-6 bg-white border">
      <div>
        <Head :title="$trans('civic.civic_alerts')" />
        <h1 class="mb-8 text-3xl font-bold">{{ $trans('civic.civic_alerts') }}</h1>
        <div class="flex items-center justify-between mb-6">
          <search-filter v-model="form.search" class="w-full max-w-md mr-4" @reset="reset" />

          <div class="justify-between mb-6 text-right">
            <Link class="mr-2 btn-sky" @click="download()">
              <span>{{ $trans('common.download') }}</span>
            </Link>
            <Link class="btn-black" href="/civic-alerts/categories">
              <span>{{ $trans('civic.manage_categories') }}</span>
            </Link>
          </div>
        </div>
        <div class="overflow-x-auto bg-white rounded-md shadow">
          <table class="w-full whitespace-nowrap">
            <tr class="font-bold text-left">
              <th class="px-6 pt-6 pb-4">{{ $trans('users.name') }}</th>
              <th class="px-6 pt-6 pb-4">{{ $trans('civic.category_name') }}</th>
              <th class="px-6 pt-6 pb-4">{{ $trans('civic.description') }}</th>
            </tr>
            <tr v-for="alert in civicAlerts" :key="alert.id" class="hover:bg-gray-100 focus-within:bg-gray-100">
              <td class="border-t">
                <span class="flex items-center px-6 py-4 focus:text-indigo-500">
                  {{ alert.user_name }}
                </span>
              </td>
              <td class="border-t">
                <span class="flex items-center px-6 py-4" tabindex="-1">
                  {{ alert.category_name }}
                </span>
              </td>
              <td class="border-t">
                <span class="flex items-center px-6 py-4" tabindex="-1">
                  {{ alert.description }}
                </span>
              </td>
            </tr>
            <tr v-if="civicAlerts.length === 0">
              <td class="px-6 py-4 border-t" colspan="4">{{ $trans('civic.no_civic_alerts_found') }}</td>
            </tr>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { Head, Link } from '@inertiajs/inertia-vue3'
import pickBy from 'lodash/pickBy'
import Layout from '@/Shared/Layout'
import throttle from 'lodash/throttle'
import mapValues from 'lodash/mapValues'
import SearchFilter from '@/Shared/SearchFilter'

export default {
  components: {
    Head,
    Link,
    SearchFilter,
  },
  layout: Layout,
  props: {
    filters: Object,
    civicAlerts: Array,
  },
  data() {
    return {
      form: {
        search: this.filters.search,
      },
    }
  },
  watch: {
    form: {
      deep: true,
      handler: throttle(function () {
        this.$inertia.get('/civic-alerts', pickBy(this.form), { preserveState: true })
      }, 150),
    },
  },
  methods: {
    reset() {
      this.form = mapValues(this.form, () => null)
    },
    download() {
      this.$inertia.get('/civic-alerts/download/csv', pickBy(this.form), { preserveState: true })
    },
  },
}
</script>
