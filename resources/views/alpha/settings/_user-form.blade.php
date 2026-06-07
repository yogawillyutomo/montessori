@php
    $isEdit = $mode === 'edit';
    $prefix = $isEdit ? "user_{$user->id}_" : 'new_user_';
@endphp

<div class="form-grid">
    <div class="field">
        <label for="{{ $prefix }}name">Nama</label>
        <input id="{{ $prefix }}name" type="text" name="name" value="{{ old('name', $user?->name) }}" required>
    </div>
    <div class="field">
        <label for="{{ $prefix }}email">Email</label>
        <input id="{{ $prefix }}email" type="email" name="email" value="{{ old('email', $user?->email) }}" required>
    </div>
    <div class="field">
        <label for="{{ $prefix }}role">Role</label>
        <select id="{{ $prefix }}role" name="role" required>
            @foreach ($roleOptions as $role => $label)
                <option value="{{ $role }}" @selected(old('role', $user?->role ?? 'admin') === $role)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="{{ $prefix }}phone">Telepon</label>
        <input id="{{ $prefix }}phone" type="text" name="phone" value="{{ old('phone', $user?->phone) }}">
    </div>
    <div class="field">
        <label for="{{ $prefix }}password">Password {{ $isEdit ? 'baru' : '' }}</label>
        <input id="{{ $prefix }}password" type="password" name="password" {{ $isEdit ? '' : 'required' }} minlength="8">
    </div>
    <div class="field">
        <label for="{{ $prefix }}password_confirmation">Konfirmasi Password</label>
        <input id="{{ $prefix }}password_confirmation" type="password" name="password_confirmation" {{ $isEdit ? '' : 'required' }} minlength="8">
    </div>
    <label class="checkbox-row wide">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user?->is_active ?? true))>
        <span>User aktif dan bisa login</span>
    </label>
</div>
