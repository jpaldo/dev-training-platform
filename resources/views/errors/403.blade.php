@extends('errors.minimal')

@section('title', 'Access denied')
@section('code', '403')
@section('message', $exception->getMessage() ?: 'You do not have permission to view this page.')
